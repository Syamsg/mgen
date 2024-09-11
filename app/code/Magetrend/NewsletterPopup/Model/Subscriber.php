<?php
/**
 * MB "Vienas bitas" (Magetrend.com)
 *
 * @category MageTrend
 * @package  Magetend/NewsletterPopup
 * @author   Edvinas Stulpinas <edwin@magetrend.com>
 * @license  http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link     http://www.magetrend.com/magento-2-newsletter-popup-extension
 */

namespace Magetrend\NewsletterPopup\Model;

use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;

class Subscriber
{
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $_request;

    /**
     * @var \Magetrend\NewsletterPopup\Model\Popup
     */
    protected $_popup = null;

    /**
     * @var \Magetrend\NewsletterPopup\Model\Campaign
     */
    protected $_campaign;

    /**
     * @var \Magetrend\NewsletterPopup\Helper\Data
     */
    protected $_helper;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $_registry;

    /**
     * @var \Magento\Framework\Stdlib\CookieManagerInterface
     */
    protected $_cookieManager;

    /**
     * @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory
     */
    protected $_cookieMetadataFactory;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    public $date;

    /**
     * @var \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory
     */
    public $subscriberCollection;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    public $customerRepository;

    /**
     * Save additional subscribers data
     */
    public $subscriberDataRegistry = null;

    /**
     * @var CheckoutSession
     */
    public $checkoutSession;

    /**
     * Subscriber constructor.
     * @param Campaign $campaign
     * @param \Magento\Framework\App\RequestInterface $requestInterface
     * @param \Magetrend\NewsletterPopup\Helper\Data $helper
     * @param \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        Campaign $campaign,
        \Magento\Framework\App\RequestInterface $requestInterface,
        \Magetrend\NewsletterPopup\Helper\Data $helper,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        CheckoutSession $checkoutSession,
        \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory $subscriberCollection,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
    ) {
        $this->_request = $requestInterface;
        $this->_campaign = $campaign;
        $this->_helper = $helper;
        $this->_registry = $registry;
        $this->_cookieManager = $cookieManager;
        $this->_cookieMetadataFactory = $cookieMetadataFactory;
        $this->date = $date;
        $this->checkoutSession = $checkoutSession;
        $this->subscriberCollection = $subscriberCollection;
        $this->customerRepository = $customerRepository;
    }

    /**
     * Save additional subscriber data and generate discount code
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @param String $email
     * @return bool
     */
    public function beforeSubscribe(\Magento\Framework\DataObject $subscriber, $email)
    {
        if (!$this->_helper->isActive()) {
            return false;
        }
        //create cookie and don't show popup again
        $this->rememberSubscriber();

        $campaignId = $this->_request->getParam('campaign_id');
        if (!$this->_helper->isActiveDefault() && !is_numeric($campaignId)) {
            //subscriber is not from popup and default subscription is disabled
            return false;
        }

        $this->saveAdditionalData($subscriber);

        $collection = $this->subscriberCollection->create()
            ->addFieldToFilter('subscriber_email', $email);

        $createNewDiscount = false;
        if ($collection->getSize() == 0) {
            $createNewDiscount = true;
        } else {
            $existingSubscriber = $collection->fetchItem();
            if (!empty($existingSubscriber->getNpDiscountCode())) {
                $this->_registry->unregister('newsletterpopup_coupon_code');
                $this->_registry->register('newsletterpopup_coupon_code', $existingSubscriber->getNpDiscountCode());
            } else {
                $createNewDiscount = true;
            }
        }

        if ($createNewDiscount) {
            $this->createDiscountCoupon($subscriber);
        }

        $this->applyToCart();
        $subscriber->setCreatedAt($this->date->date('Y-m-d H:i:s'));
        return true;
    }

    /**
     *  Generate discount code for customer
     *
     * @param \Magento\Newsletter\Model\Subscriber
     * @param Int $customerId
     * @return bool
     */
    public function beforeSubscribeCustomerById(\Magento\Framework\DataObject $subscriber, $customerId)
    {
        if (!$this->_helper->isActive()) {
            return false;
        }
        //create cookie and don't show popup again
        $this->rememberSubscriber();

        if (!$this->_helper->isActiveDefault()) {
            //subscriber is not from popup and default subscription is disabled
            return false;
        }

        $createNewDiscount = false;
        try {
            $customer = $this->customerRepository->getById($customerId);
            $collection = $this->subscriberCollection->create()
                ->addFieldToFilter('subscriber_email', $customer->getEmail());

            if ($collection->getSize() == 0) {
                $createNewDiscount = true;
            } else {
                $existingSubscriber = $collection->fetchItem();
                if (!empty($existingSubscriber->getNpDiscountCode())) {
                    $this->_registry->unregister('newsletterpopup_coupon_code');
                    $this->_registry->register('newsletterpopup_coupon_code', $existingSubscriber->getNpDiscountCode());
                } else {
                    $createNewDiscount = true;
                }
            }
        } catch (NoSuchEntityException $e) {
            $createNewDiscount = true;
        }

        if ($createNewDiscount) {
            $this->createDiscountCoupon($subscriber);
        }
        $this->applyToCart();
        $subscriber->setCreatedAt($this->date->date('Y-m-d H:i:s'));

        return true;
    }

    /**
     * Save additional subscriber data
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function saveAdditionalData(\Magento\Framework\DataObject $subscriber)
    {
        $additionalData = [];
        $popup = $this->getPopup();

        $additionalFields = $popup->getAdditionalFields();
        if (count($additionalFields) > 0) {
            foreach ($additionalFields as $field) {
                $value = $this->_request->getParam($field['name']);
                if (!empty($value) && $value != $field['label']) {
                    $additionalData[$field['name']] = $value;
                    $subscriber->setData('subscriber_'.$field['name'], $value);
                }
            }
        }
        $this->_registry->register('newsletterpopup_additional_data', $additionalData);
    }

    /**
     * Generate new discount code for subscriber
     *
     * @param \Magento\Newsletter\Model\Subscriber $subscriber
     * @return true
     */
    public function createDiscountCoupon(\Magento\Framework\DataObject $subscriber)
    {
        $popup = $this->getPopup();

        if (!$popup->getCouponIsActive()) {
            return false;
        }

        $code = $popup->getUniqueDiscountCode();
        $subscriber->setData(Popup::DISCOUNT_CODE_FIELD, $code)
            ->setData('np_popup_id', $popup->getId());

        $delay = $popup->getFollowupDelay();
        if ($popup->getFollowupIsActive() == 1 && $delay > 0) {
            $subscriber->setNpFollowupDate($this->date->date('Y-m-d H:i:s', time() + ($delay * 60)));
            $subscriber->setNpFollowupStatus(1);
        }

        $this->_registry->unregister('newsletterpopup_coupon_code');
        $this->_registry->register('newsletterpopup_coupon_code', $code);
        return true;
    }

    /**
     * Get Popup Object
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return \Magetrend\NewsletterPopup\Model\Popup
     */
    protected function getPopup()
    {
        if ($this->_popup == null) {
            $this->_popup = $this->getCampaign()->getPopup();
            if (!$this->_popup->getId()) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Popup is no longer available'));
            }
        }
        return $this->_popup;
    }

    /**
     * Get Campaign Object
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return \Magetrend\NewsletterPopup\Model\Campaign
     */
    protected function getCampaign()
    {
        if (!$this->_campaign->getId()) {
            $campaignId = $this->_request->getParam('campaign_id');

            if (!is_numeric($campaignId)) {
                $campaignId = $this->_helper->getDefaultCampaignId();
            }

            $this->_campaign->load($campaignId);
            if (!$this->_campaign->getId()) {
                throw new \Magento\Framework\Exception\LocalizedException(__('Campaign is no longer available'));
            }
        }
        return $this->_campaign;
    }

    /**
     * Save information to cookie
     */
    public function rememberSubscriber()
    {
        $cookieName = $this->_helper->getSubscriberCookieName();
        $cookieMetadata = $this->_cookieMetadataFactory->createPublicCookieMetadata()
            ->setHttpOnly(false)
            ->setDuration(31556926)
            ->setPath('/');
        $this->_cookieManager->setPublicCookie($cookieName, 1, $cookieMetadata);
    }

    /**
     * Apply discount code to cart automatically
     *
     * @return bool
     */
    public function applyToCart()
    {
        $popup = $this->getPopup();
        $code = $this->_registry->registry('newsletterpopup_coupon_code');
        if (empty($code) || $popup->getData('apply_to_cart') != 1) {
            return false;
        }

        $this->checkoutSession->getQuote()
            ->setCouponCode($code)
            ->collectTotals()
            ->save();

        $cookieName = $this->_helper->getSubscriberCookieName();
        $cookieMetadata = $this->_cookieMetadataFactory->createPublicCookieMetadata()
            ->setHttpOnly(false)
            ->setDuration(60*60*12)
            ->setPath('/');
        $this->_cookieManager->setPublicCookie('mtns-c', base64_encode($code), $cookieMetadata);

        return true;
    }
}
