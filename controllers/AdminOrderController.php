<?php

use OnlineShop\Framework\Impl\OrderManager\Order\Listing\Filter;

class OnlineShop_AdminOrderController extends Pimcore\Controller\Action\Admin
{
    /**
     * @var OnlineShop\Framework\IOrderManager
     */
    protected $orderManager;


    public function init()
    {
        parent::init();


        // enable layout only if its a normal request
        if($this->getRequest()->isXmlHttpRequest() === false)
        {
            $this->enableLayout();
            $this->setLayout('back-office');
        }


        // sprache setzen
        $language = $this->getUser() ? $this->getUser()->getLanguage() : 'en';
        Zend_Registry::set("Zend_Locale", new Zend_Locale( $language ));
        $this->language = $language;
        $this->view->language = $language;
        $this->initTranslation();


        // enable inherited values
        Object_Abstract::setGetInheritedValues(true);
        Object_Localizedfield::setGetFallbackValues(true);


        // init
        $this->orderManager = OnlineShop_Framework_Factory::getInstance()->getOrderManager();
    }


    protected function initTranslation() {

        $translate = null;
        if(Zend_Registry::isRegistered("Zend_Translate")) {
            $t = Zend_Registry::get("Zend_Translate");
            // this check is necessary for the case that a document is rendered within an admin request
            // example: send test newsletter
            if($t instanceof Pimcore_Translate) {
                $translate = $t;
            }
        }

        if(!$translate) {
            // setup Zend_Translate
            try {
                $locale = Zend_Registry::get("Zend_Locale");

                $translate = new Pimcore_Translate_Website($locale);

                if(Pimcore_Tool::isValidLanguage($locale)) {
                    $translate->setLocale($locale);
                } else {
                    Logger::error("You want to use an invalid language which is not defined in the system settings: " . $locale);
                    // fall back to the first (default) language defined
                    $languages = Pimcore_Tool::getValidLanguages();
                    if($languages[0]) {
                        Logger::error("Using '" . $languages[0] . "' as a fallback, because the language '".$locale."' is not defined in system settings");
                        $translate = new Pimcore_Translate_Website($languages[0]); // reinit with new locale
                        $translate->setLocale($languages[0]);
                    } else {
                        throw new Exception("You have not defined a language in the system settings (Website -> Frontend-Languages), please add at least one language.");
                    }
                }


                // register the translator in Zend_Registry with the key "Zend_Translate" to use the translate helper for Zend_View
                Zend_Registry::set("Zend_Translate", $translate);
            }
            catch (Exception $e) {
                Logger::error("initialization of Pimcore_Translate failed");
                Logger::error($e);
            }
        }

        return $translate;
    }


    /**
     * Bestellungen auflisten
     */
    public function listAction()
    {
        // create new order list
        $list = $this->orderManager->createOrderList();

        // set list type
        $list->setListType( $this->getParam('type', $list::LIST_TYPE_ORDER) );


        // add select fields
        $list->addSelectField('order.OrderDate');
        $list->addSelectField(['OrderNumber' => 'order.orderNumber']);
//        $list->addSelectField(['PaymentReference' => 'order.paymentReference']);
        if($list->getListType() == $list::LIST_TYPE_ORDER)
        {
            $list->addSelectField(['TotalPrice' => 'order.totalPrice']);
        }
        else if($list->getListType() == $list::LIST_TYPE_ORDER_ITEM)
        {
            $list->addSelectField(['TotalPrice' => 'orderItem.totalPrice']);
        }
        $list->addSelectField(['Items' => 'count(orderItem.o_id)']);


        // Search
        if($this->getParam('q'))
        {
            $q = htmlentities($this->getParam('q'));
            $search = $this->getParam('search');
            switch($search)
            {
                case 'paymentReference':
//                    $list->setFilterPaymentReference( $this->getParam('q') );
//                    break;

                case 'email':
                case 'customer':
                default:
                    $filterCustomer = new Filter\Customer();

                    if($search == 'customer')
                    {
                        $filterCustomer->setName( $q );
                    }
                    if($search == 'email')
                    {
                        $filterCustomer->setEmail( $q );
                    }

                    $list->addFilter( $filterCustomer );
                    break;
            }
        }



        // add Date Filter
        $filterDate = new Filter\OrderDateTime();
        if($this->getParam('from') || $this->getParam('till') )
        {
            $from = $this->getParam('from') ? new Zend_Date($this->getParam('from')) : null;
            $till = $this->getParam('till') ? new Zend_Date($this->getParam('till')) : null;
            if ($till){
                $till->add(1,Zend_Date::DAY);
            }

            if($from)
            {
                $filterDate->setFrom( $from );
            }
            if($till)
            {
                $filterDate->setTill( $till );
            }
        }
        else
        {
            // als default, nehmen wir den ersten des aktuellen monats
            $from = new Zend_Date();
            $from->setDay(1);

//            $filterDate->setFrom( $from );
//            $this->setParam('from', $from->get(Zend_Date::DATE_MEDIUM));
        }
        $list->addFilter( $filterDate );


        $list->setOrder( 'order.orderDate desc' );



        // create paging
        $paginator = Zend_Paginator::factory( $list );
        $paginator->setItemCountPerPage( 10 );
        $paginator->setCurrentPageNumber( $this->getParam('page', 1) );

        // view
        $this->view->paginator = $paginator;
    }


    /**
     * details der bestellung anzeigen
     * @todo
     */
    public function detailAction()
    {
        // init
        $order = Object_OnlineShopOrder::getById( $this->getParam('id') );
        $this->view->orderAgent = $this->orderManager->createOrderAgent( $order );


        /**
         * @param array $address
         *
         * @return string
         */
        $geoPoint = function (array $address) {
            # https://developers.google.com/maps/documentation/geocoding/index?hl=de#JSON
            $url = sprintf('http://maps.googleapis.com/maps/api/geocode/json?address=%1$s&sensor=false'
                , urlencode(
                    $address[0]
                    . ' ' . $address[1]
                    . ' ' . $address[2]
                    . ' ' . $address[3]
                )
            );
            $json = json_decode(file_get_contents( $url ));
            return $json->results[0]->geometry->location;
        };


        // get geo point
        $this->view->geoAddressInvoice = $geoPoint([$order->getCustomerStreet(), $order->getCustomerZip(), $order->getCustomerCity(), $order->getCustomerCountry()]);
        if($order->getDeliveryStreet() && $order->getDeliveryZip())
        {
            $this->view->geoAddressDelivery = $geoPoint([$order->getDeliveryStreet(), $order->getDeliveryZip(), $order->getDeliveryCity(), $order->getDeliveryCountry()]);
        }
    }


    /**
     * cancel order item
     */
    public function cancelItemAction()
    {
        // init
        $this->view->orderItem = $orderItem = Object_OnlineShopOrderItem::getById( $this->getParam('id') );
//        $this->view->orderItem = $orderItem;
//        $order = $orderItem->getOrder();
//
//
//        if($this->getParam('confirmed') && $orderItem->isCancelAble())
//        {
//            $orderManager = Website_Shop_Order_Manager::getInstance( $orderItem->getPaymentOrder() );
//            $orderManager->cancelItem( $orderItem );
//
//
//            // redir
//            $url = $this->view->url(['action' => 'detail', 'controller' => 'admin-order', 'module' => 'BackOffice', 'id' => $order->getId()], 'plugin', true);
//            $this->redirect( $url );
//        }
    }


    /**
     * edit item
     */
    public function editItemAction()
    {
        $this->view->orderItem = $orderItem = Object_OnlineShopOrderItem::getById( $this->getParam('id') );
        /* @var \Pimcore\Model\Object\OnlineShopOrderItem $orderItem */


        if($this->getParam('confirmed'))
        {
            // TODO change item
            $agent = $this->orderManager->createOrderAgent( $orderItem->getOrder() );

            $agent->addHook('item.change.amount', function ($event) {

                $a = $event->getTarget();
                $note = $a->note;

                /* @var \Pimcore\Model\Element\Note $note */
                $note->addData('comment', 'text', 'telefonisch geändert');

            });

            $agent->itemChangeAmount($orderItem, 5);


            // TODO redir
//            $url = $this->view->url(['action' => 'detail', 'controller' => 'admin-order', 'module' => 'BackOffice', 'id' => $order->getId()], 'plugin', true);
//            $this->redirect( $url );
        }
    }
}
