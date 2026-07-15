<?php
if(!defined('ABSPATH'))exit;

/** Privacy-safe decision outcome instrumentation. */
final class YOGB_BM_Outcomes {
	const META_REF='_yogb_gbl_decision_ref';const META_AT='_yogb_gbl_decision_at';const OPT_CAPABILITIES='yogb_bm_server_capabilities';
	public static function init():void{
		add_action('woocommerce_payment_complete',static function($id){self::capture($id,'payment_completed','woocommerce');});
		add_action('woocommerce_order_status_completed',static function($id){self::capture($id,'order_completed','woocommerce');});
		add_action('woocommerce_order_status_cancelled',static function($id){self::capture($id,'order_cancelled','woocommerce');});
		add_action('woocommerce_order_refunded',[__CLASS__,'capture_refund'],10,2);
		add_action('yogb_bm_chargeback_confirmed',static function($id){self::capture($id,'chargeback_confirmed','processor');});
		add_action('yogb_bm_confirm_fraud',static function($id){self::capture($id,'fraud_confirmed','merchant');});
		add_action('yogb_bm_confirm_false_positive',static function($id){self::capture($id,'false_positive_confirmed','merchant');});
		add_action('yogb_bm_appeal_accepted',static function($id){self::capture($id,'appeal_accepted','appeal');});
		add_action('yogb_bm_appeal_rejected',static function($id){self::capture($id,'appeal_rejected','appeal');});
		add_action('yogb_bm_manual_review_passed',static function($id){self::capture($id,'manual_review_passed','merchant');});
		add_action('yogb_bm_manual_review_failed',static function($id){self::capture($id,'manual_review_failed','merchant');});
	}
	public static function supports():bool{return in_array('decision_outcomes_v1',(array)get_option(self::OPT_CAPABILITIES,[]),true);}
	public static function capture($order_id,string $type,string $source='woocommerce',array $metadata=[]):int{
		if(!self::supports()||!class_exists('YOGB_BM_Outbox'))return 0;$order=wc_get_order(absint($order_id));if(!$order)return 0;$ref=(string)$order->get_meta(self::META_REF,true);if(!preg_match('/^gbl_dec_[a-f0-9]{32}$/',$ref))return 0;
		$event_key=$ref.'|'.$type.'|'.$source;$uuid=self::uuid_from_hash(hash('sha256',$event_key));$safe=[];foreach(['refund_ratio','payment_state','review_source','reason_code','order_age_days'] as $key){if(isset($metadata[$key])&&is_scalar($metadata[$key])){$safe[$key]=substr(sanitize_text_field((string)$metadata[$key]),0,64);}}
		return YOGB_BM_Outbox::enqueue_outcome(['event_uuid'=>$uuid,'decision_ref'=>$ref,'outcome_type'=>$type,'source'=>$source,'occurred_at'=>gmdate('c'),'metadata'=>$safe],(int)$order->get_id());
	}
	public static function capture_refund($order_id,$refund_id=0):void{$order=wc_get_order(absint($order_id));if(!$order)return;$total=max(0.0,(float)$order->get_total());$refunded=abs((float)$order->get_total_refunded());$ratio=$total>0?min(1.0,$refunded/$total):0.0;self::capture($order_id,$ratio>=0.999?'refund_full':'refund_partial','woocommerce_refund',['refund_ratio'=>(string)round($ratio,4),'reason_code'=>'refund']);}
	private static function uuid_from_hash(string $hash):string{$hex=substr($hash,0,32);return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20,12);}
}
YOGB_BM_Outcomes::init();
