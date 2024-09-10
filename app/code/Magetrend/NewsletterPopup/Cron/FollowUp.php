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

namespace Magetrend\NewsletterPopup\Cron;

use Magento\Newsletter\Model\ResourceModel\Subscriber\Collection;
use Magento\Newsletter\Model\Subscriber;

/**
 * Send follow up message cron class
 *
 * @category MageTrend
 * @package  Magetend/NewsletterPopup
 * @author   Edvinas Stulpinas <edwin@magetrend.com>
 * @license  http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link     https://www.magetrend.com/magento-2-discount-for-next-order
 */
class FollowUp
{
    const STATUS_SENT = 9;

    const STATUS_DISABLED = 7;

    const STATUS_PROCESSED = 6;

    const STATUS_NEED_TO_SEND = 1;

    const STATUS_COUPON_USED = 2;

    const STATUS_NO_COUPON = 3;

    const STATUS_NO_RULE = 4;

    const STATUS_NO_POPUP = 5;

    /**
     * @var \Magetrend\NewsletterPopup\Helper\Data
     */
    public $helper;

    /**
     * @var \Magento\SalesRule\Model\ResourceModel\Coupon\CollectionFactory
     */
    public $couponCollection;

    /**
     * @var \Magetrend\NewsletterPopup\Model\Coupon
     */
    public $coupon;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    public $storeManager;

    /**
     * @var \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory
     */
    public $subscriberCollectionFactory;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    public $date;

    /**
     * @var \Magento\SalesRule\Model\CouponFactory
     */
    public $couponFactory;

    /**
     * @var \Magento\SalesRule\Model\RuleFactory
     */
    public $ruleFactory;

    /**
     * @var \Magetrend\NewsletterPopup\Model\PopupFactory
     */
    public $popupFactory;

    public $transportBuilder;

    /**
     * FollowUp constructor.
     * @param \Magetrend\NewsletterPopup\Helper\Data $helper
     * @param \Magetrend\NewsletterPopup\Model\PopupFactory $popupFactory
     * @param \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory $subscriberCollectionFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\SalesRule\Model\CouponFactory $couponFactory
     * @param \Magento\SalesRule\Model\RuleFactory $ruleFactory
     * @param \Magetrend\NewsletterPopup\Model\Mail\Template\TransportBuilder $transportBuilder
     */
    public function __construct(
        \Magetrend\NewsletterPopup\Helper\Data $helper,
        \Magetrend\NewsletterPopup\Model\PopupFactory $popupFactory,
        \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory $subscriberCollectionFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\SalesRule\Model\CouponFactory $couponFactory,
        \Magento\SalesRule\Model\RuleFactory $ruleFactory,
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder
    ) {
        $this->helper = $helper;
        $this->popupFactory = $popupFactory;
        $this->storeManager = $storeManager;
        $this->subscriberCollectionFactory = $subscriberCollectionFactory;
        $this->date = $date;
        $this->couponFactory = $couponFactory;
        $this->ruleFactory = $ruleFactory;
        $this->transportBuilder = $transportBuilder;
    }

    /**
     * Method triggered by cron
     * Execute if delay time is set longer than 0
     * Will send delayed coupons
     * @return bool
     */
    public function execute()
    {
        if (!$this->helper->isActive()) {
            return false;
        }

        $collection = $this->subscriberCollectionFactory->create()
            ->addFieldToFilter(\Magetrend\NewsletterPopup\Model\Popup::DISCOUNT_CODE_FIELD, ['notnull' => true])
            ->addFieldToFilter(\Magetrend\NewsletterPopup\Model\Popup::DISCOUNT_CODE_FIELD, ['neq' => ''])
            ->addFieldToFilter('np_followup_status', self::STATUS_NEED_TO_SEND)
            ->addFieldToFilter('np_popup_id', ['neq' => 0])
            ->addFieldToFilter('np_followup_date', ['lteq' => $this->date->date('Y-m-d H:i:s')])
            ->addFieldToFilter('np_followup_date', ['gteq' => $this->date->date('Y-m-d H:i:s', time() - 86400)])
            ->setPageSize(1)
            ->setCurPage(1);

        if ($collection->getSize() == 0) {
            return;
        }

        $subscriber = $collection->getFirstItem();
        $couponCode = $subscriber->getData(\Magetrend\NewsletterPopup\Model\Popup::DISCOUNT_CODE_FIELD);
        $subscriber->setData('np_followup_status', self::STATUS_PROCESSED)
            ->save();

        $coupon = $this->couponFactory->create();
        $coupon->load($couponCode, 'code');
        if (!$coupon->getId()) {
            $subscriber->setData('np_followup_status', self::STATUS_NO_COUPON)
                ->save();
            return;
        }

        if ($coupon->getTimesUsed() > 0) {
            $subscriber->setData('np_followup_status', self::STATUS_COUPON_USED)
                ->save();
            return;
        }

        $rule = $this->ruleFactory->create();
        $rule->load($coupon->getRuleId());

        if (!$rule->getId()) {
            $subscriber->setData('np_followup_status', self::STATUS_NO_RULE)
                ->save();
            return;
        }

        $popup = $this->popupFactory->create()
            ->load($subscriber->getNpPopupId());

        if (!$popup->getId()) {
            $subscriber->setData('np_followup_status', self::STATUS_NO_POPUP)
                ->save();
            return;
        }

        $this->_sendFollowUpMessage($subscriber, $popup, $coupon, $rule);
        return true;
    }

    protected function _sendFollowUpMessage($subscriber, $popup, $coupon, $rule)
    {
        $storeId = $subscriber->getStoreId();
        $templateId = $popup->getData('followup_email_template');
        $senderName = $popup->getData('followup_sender_email');
        $senderEmail = $popup->getData('followup_sender_email');
        $sendTo = $subscriber->getData('subscriber_email');

        $transport = $this->transportBuilder->setTemplateIdentifier($templateId)
            ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars([
                'subscriber' => $subscriber,
                'popup' => $popup,
                'coupon' => $coupon,
                'rule' => $rule,
                'coupon_data' => [
                    'code' => $coupon->getCode()
                ]
            ])
            ->setFrom([
                'email' => $senderEmail,
                'name' => $senderName
            ])
            ->addTo($sendTo)
            ->setReplyTo($senderEmail, $senderName)
            ->getTransport();
        $transport->sendMessage();

        $subscriber->setData('np_followup_status', self::STATUS_SENT)
            ->save();
    }
}
