<?php

/**
 * @package ShopStoreSiteaccess
 * @author  Serhey Dolgushev <dolgushev.serhey@gmail.com>
 * @date    18 Dec 2012
 * */
class shopStoreSiteaccessType extends eZWorkflowEventType {

    const TYPE_ID = 'shopstoresiteaccess';

    public function __construct() {
        $this->eZWorkflowEventType(
            self::TYPE_ID, ezpI18n::tr( 'extension/shop_store_siteaccess', 'Store used siteaccess' )
        );
        $this->setTriggerTypes(
            array(
                'shop' => array(
                    'confirmorder' => array( 'before' ),
                    'checkout'     => array( 'after' )
                )
            )
        );
    }

    public function execute( $process, $event ) {
        // we should not do nothing, if it is called from CLI
        if( eZSys::isShellExecution() ) {
            return eZWorkflowEventType::STATUS_ACCEPTED;
        }

        $parameters = $process->attribute( 'parameter_list' );
        if( isset( $parameters['order_id'] ) === false ) {
            return eZWorkflowEventType::STATUS_ACCEPTED;
        }
        $order = eZOrder::fetch( $parameters['order_id'] );
        if( $order instanceof eZOrder === false ) {
            return eZWorkflowEventType::STATUS_ACCEPTED;
        }

        $check = eZOrderItem::fetchListByType( $order->attribute( 'id' ), 'siteaccess' );
        if( count( $check ) > 0 && $order->attribute( 'is_temporary' ) && $process->ParameterList['trigger_name'] != 'post_checkout' ) {
            return eZWorkflowEventType::STATUS_ACCEPTED;
        }

        if( count( $check ) > 0 ) {
            foreach( $check as $item ) {
                $item->remove();
            }
        }

        $orderItem = new eZOrderItem(
            array(
            'order_id'        => $order->attribute( 'id' ),
            'description'     => $GLOBALS['eZCurrentAccess']['name'],
            'price'           => 0,
            'type'            => 'siteaccess',
            'vat_is_included' => true,
            'vat_type_id'     => 1
            )
        );
        $orderItem->store();

        return eZWorkflowType::STATUS_ACCEPTED;
    }

    public static function fix( $output ) {
        // Order is complete if there is no current basket
        $basket = eZBasket::currentBasket();
        if( $basket instanceof eZBasket === false ) {
            return $output;
        }

        // Check if current order is paid (in some rare cases order is completed, but it still has the basket)
        $db = eZDB::instance();
        $q  = 'SELECT o.id
            FROM ezorder o
            LEFT JOIN xrowpaymentobject p ON p.order_id = o.id
            WHERE o.id = ' . $basket->attribute( 'order_id' ) . ' AND p.status = 1';
        if( count( $db->arrayQuery( $q ) ) > 0 ) {
            return $output;
        }

        // Check if there is "active" multisafepay transaction
        if( class_exists( 'MultiSafepayTransaction' ) ) {
            $transcation = eZPersistentObject::fetchObject(
                    MultiSafepayTransaction::definition(), null, array( 'order_id' => $basket->attribute( 'order_id' ) ), true
            );
            if( $transcation instanceof MultiSafepayTransaction ) {
                return $output;
            }
        }

        $items = eZOrderItem::fetchListByType( $basket->attribute( 'order_id' ), 'siteaccess' );
        if( count( $items ) > 0 ) {
            $item = $items[0];
            if( $item->attribute( 'description' ) != $GLOBALS['eZCurrentAccess']['name'] ) {
                $item->setAttribute( 'description', $GLOBALS['eZCurrentAccess']['name'] );
                $item->store();
            }
        }

        return $output;
    }

}

eZWorkflowEventType::registerEventType(
    shopStoreSiteaccessType::TYPE_ID, 'shopStoreSiteaccessType'
);
