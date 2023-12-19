<?php
/**
 * @package SD Plugins
 */
/*
Plugin Name: SD WooCommerce REST API modifier
Plugin URI: https://sudipdebnathofficial.com/
Description: Woocommerce REST API modification to make compatibility with Tally.
Version: 3.4
Author: Sudip Debnath (SD)
Author URI: https://sudipdebnathofficial.com/
License: GPLv3
Text Domain: sd
*/

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
  echo 'Hi buddy!  I\'m just a plugin, not much I can do when called directly.';
  exit;
}

define( 'SD_WRAM_VERSION', '1.0.1' );
define( 'SD_WRAM__MINIMUM_WP_VERSION', '5.0' );
define('SD_WRAM_DIR', plugin_dir_path(__FILE__));
define('SD_WRAM_URL', plugin_dir_url(__FILE__));

require_once( SD_WRAM_DIR . 'inc/sd_wram_init.php' );
register_activation_hook( __FILE__, array( 'SD_WRAM_INIT', 'Activate' ) );
register_deactivation_hook( __FILE__, array( 'SD_WRAM_INIT', 'Deactivate' ) );

/*===========================*/

function sd_get_order_payment_receipt_field(){
  return 'payment_receipt_details';
}

function sd_get_order_expense_receipt_field(){
  return 'payment_expense_details';
}

// Modify woocommerce REST API for single order
add_filter('woocommerce_rest_prepare_shop_order_object', 'sd_filter_order_response', PHP_INT_MAX, 3);
function sd_filter_order_response($response, $order, $request){
  $response_data = $response->data;
  $wcb2bsa_sales_agent = get_post_meta($order->get_id(), 'wcb2bsa_sales_agent', true);
  $sales_agent_name = '';
  if($wcb2bsa_sales_agent){
    $user = get_user_by('id', $wcb2bsa_sales_agent);
    $sales_agent_name = $user->display_name;
  }
  $response_data['sales_agent_id'] = $wcb2bsa_sales_agent;
  $response_data['sales_agent_name'] = $sales_agent_name;

  $response_data['import_type'] = 'all';

  $import_only_payment_receipts = get_post_meta($order->get_id(), 'import_only_payment_receipts', true);
  $import_only_discount = get_post_meta($order->get_id(), 'import_only_discount', true);

  if(function_exists('get_field')){

    $payment_receipts = get_field(sd_get_order_payment_receipt_field(), $order->get_id());

    $sorted_receipts = array();

    if($payment_receipts && !empty($payment_receipts)){
      foreach ($payment_receipts as $_pr) {
        if($_pr['rcptImportToTally'] == 'true')
        {
          $sorted_receipts[] = $_pr;
        }
      }
      $response_data['ReceiptVch'] = $sorted_receipts;
    }

    $expense_receipts = get_field(sd_get_order_expense_receipt_field(), $order->get_id());
    if($expense_receipts && !empty($expense_receipts)){
      $response_data['ExpenseVch'] = $expense_receipts;
    }

    // Only payment receipts tag
    if($import_only_payment_receipts){
      $response_data['import_type'] = 'only_payment_receipts';
    }

    // Only discount tag
    if($import_only_discount){
      $response_data['import_type'] = 'only_discount';
    }

    // Both payments & discount
    if($import_only_payment_receipts && $import_only_discount){
      $response_data['import_type'] = 'payment_receipts_and_discount';
    }

  }

  return $response_data;
}

add_filter('woocommerce_rest_orders_prepare_object_query', function(array $args, \WP_REST_Request $request){

  global $wpdb;

  if(function_exists('get_field')){
    $disable_woocommerce_rest_api_orders_pagination = get_field('disable_woocommerce_rest_api_orders_pagination', 'option');
    if($disable_woocommerce_rest_api_orders_pagination){
      $args['posts_per_page'] = 100;
      $args['numberposts'] = 100;
    }
  }
  
  $tally = isset($request['tally']) ? $request['tally'] : '';
  $date_after = isset($request['date_after']) ? $request['date_after'] : '';
  $date_before = isset($request['date_before']) ? $request['date_before'] : '';
  $customer_fname = isset($request['customer_fname']) ? $request['customer_fname'] : '';
  $customer_lname = isset($request['customer_lname']) ? $request['customer_lname'] : '';

  if($date_after){
    $args['date_query'][0]['column'] = 'post_date';
    $args['date_query'][0]['after']  = $date_after;
  }
  if($date_before){
    $args['date_query'][0]['column'] = 'post_date';
    $args['date_query'][0]['before']  = $date_before;
  }

  if($date_after || $date_before){
    $args['date_query'][0]['inclusive']  = true;
  }
   $args['meta_query'][] = array(
    'key' => 'allow_tally_import',
    'value' => 1
  );
  // When trying to fetch orders from a specific Tally Account
  if($tally){
    $tally = sanitize_text_field($tally);
    $args['meta_query'][] = array(
      'relation' => 'AND',
      array(
        'key' => 'allow_tally_import',
        'value' => 1
      ),
      array(
        'key' => 'tally_account',
        'value' => $tally
      )
    );
  }else{ // Static rule
    $args['meta_query'][] = array(
      'key' => 'allow_tally_import',
      'value' => 1
    );
  }

  if($customer_fname || $customer_lname){
    $args['meta_query'][] = array(
      'relation' => 'OR',
      array(
        'key' => '_billing_first_name',
        'value' => $customer_fname,
        'compare' => 'LIKE'
      ),
      array(
        'key' => '_billing_last_name',
        'value' => $customer_lname,
        'compare' => 'LIKE'
      ),
      array(
        'key' => '_shipping_first_name',
        'value' => $customer_fname,
        'compare' => 'LIKE'
      ),
      array(
        'key' => '_shipping_last_name',
        'value' => $customer_lname,
        'compare' => 'LIKE'
      )
    );
  }

  return $args;

}, 10, 2);


add_action('save_post_shop_order', function($post_id, $post, $update){

  if(!$update){
    update_post_meta($post_id, 'allow_tally_import', 1);
  }

  if($update){ // Enable Tally import on Order update
    // $_POST['acf']['field_63c6676055a26'] = 1;
    // update_post_meta($post_id, 'allow_tally_import', 1);
  }

}, 10, 3);

add_action('rest_api_init', 'sd_register_custom_rest_apis');
function sd_register_custom_rest_apis(){
  register_rest_route('wc/v3/', 'tally_imported_orders', [
    'methods'  => 'POST',
    'callback' => 'sd_tally_imported_orders'
  ]);

  register_rest_route('wc/v3/', 'sd_orders', [
    'methods'  => 'GET',
    'callback' => 'sd_customized_orders_for_tally'
  ]);

  register_rest_route('wc/v3/', 'sd_orders', [
    'methods'  => 'POST',
    'callback' => 'sd_get_receipt_data_for_tally'
  ]);

  register_rest_route('wc/v3/', 'create_pr_logs', [
    'methods'  => 'POST',
    'callback' => 'sd_create_pr_logs'
  ]);

  register_rest_route('wc/v3/', 'eaaca_jv', [
    'methods'  => 'GET',
    'callback' => 'sd_send_jv_data_to_tally'
  ]);

  register_rest_route('wc/v3/', 'eaaca_jv', [
    'methods'  => 'POST',
    'callback' => 'sd_get_jv_data_from_tally'
  ]);

  
}

function generate_log( $log ){
    $log .= print_r($log, true);
    //$log .= "---------------------------------------------------\n\n";
    file_put_contents( SD_WRAM_DIR.'/debug.txt', $log, FILE_APPEND );
}


function sd_get_jv_data_from_tally(){
    $post_data = json_decode(file_get_contents('php://input'));
    generate_log( "JV posted data" );
    generate_log( $post_data );
    return true;
}

function sd_send_jv_data_to_tally($data){
    $request_params = $data->get_params();
    $query_string   = http_build_query($request_params);
    $apibase        = home_url('/wp-json/wc/v3/orders');
    $endpoint       = $apibase.'?'.$query_string.'&_fields=id';

    // $app_user = isset($request_params['username']) ? $request_params['username'] : '';
    // $app_pass = isset($request_params['password']) ? $request_params['password'] : '';

    $args = array();
    if($app_user && $app_pass){
      $args['headers'] = array(
       'Authorization' => 'Basic ' . base64_encode( $app_user . ':' . $app_pass ),
      );
    }

    // $args['timeout'] = 120;
    $response = wp_remote_get( $endpoint, $args);

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    $response_data = json_decode($response_body, true);

    $jv_data   = array();
    $jv_data[] = array(
                        "VchNo"     => "VchNo Number",
                        "date"      => "11-09-2023",
                        "narration" => "Test 2",
                        "Dr_Ledger" => array(
                                          array(
                                            "LedgerName"        => "Ledger Name", //Customer Name
                                            "Amount"            => "5000",
                                          ),
                                      ),
                        "Cr_Ledger" => array(
                                            array(
                                                "LedgerName"    => "SA tax", //Type from Expenses
                                                "Amount"        => "5000",

                                                )     
                                    ),
                    );
              
    $response_data = json_decode($jv_data);
    return $response_data;
}

/*
    POST REQUEST FROM TALLY FOR RECEIPTS
*/
function sd_get_receipt_data_for_tally(){
   $php_input = file_get_contents('php://input');
    
   generate_log( "SD GET RECEIPT DATA FOR TALLY" );
   generate_log( $php_input );
   $post_data = json_decode($php_input);

    // generate_log( $post_data );
    if(isset($post_data->log) && !empty($post_data->log)){
        $totalcount = count($post_data->log);
        $success_count = 0;
        $failed_count  = 0;
        foreach ($post_data->log as $object) {
            $import_date            = isset($object->import_date) ? $object->import_date : '';
            $tally_user_name        = isset($object->tally_user_name) ? $object->tally_user_name : '';
            $tally_company_name     = isset($object->tally_company_name) ? $object->tally_company_name : '';
            $tally_ref_id           = isset($object->tally_ref_id) ? $object->tally_ref_id : '';
            $ordernumber            = isset($object->ordernumber) ? $object->ordernumber : '';
            $amount                 = isset($object->amount) ? $object->amount : '';
            $dr_ledger              = isset($object->dr_ledger) ? $object->dr_ledger : '';
            $cr_ledger              = isset($object->cr_ledger) ? $object->cr_ledger : '';
            $description            = isset($object->description) ? $object->description : '';
            $status                 = isset($object->status) ? $object->status : '';
            $order_id               = $ordernumber;

            if($ordernumber && wc_get_order($ordernumber)){
                    $tally_import_history = get_post_meta($ordernumber, 'tally_import_pr_history', true) ? get_post_meta($ordernumber, 'tally_import_pr_history', true) : null;
                    $log_data = array(
                      'import_date'         => $import_date,
                      'tally_user_name'     => $tally_user_name,
                      'tally_company_name'  => $tally_company_name,
                      'tally_ref_id'        => $tally_ref_id,
                      'ordernumber'         => $ordernumber,
                      'amount'              => $amount,
                      'dr_ledger'           => $dr_ledger,
                      'cr_ledger'           => $cr_ledger,
                      'description'         => $description
                    );
                    $tally_import_history_array = array();
                    if($tally_import_history){
                        $tally_import_history_array = json_decode($tally_import_history, true);
                    }
                    if(!sd_check_same_log_created($tally_import_history_array, $log_data)){
                        $tally_import_history_array[] = $log_data;
                    }

                    update_post_meta($order_id, 'tally_import_pr_history', json_encode($tally_import_history_array));
                    $success_count++;
                  }else{
                    $failed_count++;
                  }
        }

        /*
            CHECKMARK LOGIC
            UNCHECK THE Rcpt Import To Tally and add Timestamp
        */
        return array(
          'status'          => $success_count ? 'success' : 'error',
          'orders'          => $success_count ? 'updated' : 'no orders found',
          'totalcount'      => $totalcount,
          'success_count'   => $success_count,
          'failed_count'    => $failed_count
        );
  }
}

function sd_tally_imported_orders($data){
   $php_input = file_get_contents('php://input');
   generate_log( "SD TALLY IMPORTED ORDERS" );
   generate_log( $php_input );

  $post_data = json_decode($php_input);

  if(isset($post_data->log) && !empty($post_data->log)){
    $totalcount = count($post_data->log);
    $success_count = 0;
    $failed_count = 0;
    foreach ($post_data->log as $object) {
      $import_date = isset($object->import_date) ? $object->import_date : '';
      $tally_user_name = isset($object->tally_user_name) ? $object->tally_user_name : '';
      $tally_company_name = isset($object->tally_company_name) ? $object->tally_company_name : '';
      $tally_ref_id = isset($object->tally_ref_id) ? $object->tally_ref_id : '';
      $ordernumber = isset($object->ordernumber) ? $object->ordernumber : '';
      $amount = isset($object->amount) ? $object->amount : '';
      $dr_ledger = isset($object->dr_ledger) ? $object->dr_ledger : '';
      $cr_ledger = isset($object->cr_ledger) ? $object->cr_ledger : '';
      $description = isset($object->description) ? $object->description : '';
      $status = isset($object->status) ? $object->status : '';

      $order_id = $ordernumber;

      if($ordernumber && wc_get_order($ordernumber)){
        $tally_import_history = get_post_meta($ordernumber, 'tally_import_history', true) ? get_post_meta($ordernumber, 'tally_import_history', true) : null;

        $log_data = array(
          'import_date' => $import_date,
          'tally_user_name' => $tally_user_name,
          'tally_company_name' => $tally_company_name,
          'tally_ref_id' => $tally_ref_id,
          'ordernumber' => $ordernumber,
          'amount' => $amount,
          'dr_ledger' => $dr_ledger,
          'cr_ledger' => $cr_ledger,
          'description' => $description
        );
        $tally_import_history_array = array();
        if($tally_import_history){
          $tally_import_history_array = json_decode($tally_import_history, true);
        }else{
        }
        if(!sd_check_same_log_created($tally_import_history_array, $log_data)){
          $tally_import_history_array[] = $log_data;
        }

        update_post_meta($order_id, 'tally_import_history', json_encode($tally_import_history_array));
        update_post_meta($order_id, 'imported_into_tally', $import_date);
        update_post_meta($order_id, 'allow_tally_import', '');
        $success_count++;
      }else{
        $failed_count++;
      }
    }

    return array(
      'status' => $success_count ? 'success' : 'error',
      'orders' => $success_count ? 'updated' : 'no orders found',
      'totalcount'  => $totalcount,
      'success_count' => $success_count,
      'failed_count' => $failed_count
    );
  }

}

function sd_customized_orders_for_tally($data){
  $request_params = $data->get_params();
  $query_string = http_build_query($request_params);
  $apibase = home_url('/wp-json/wc/v3/orders');
  $endpoint = $apibase.'?'.$query_string;

  // $app_user = isset($request_params['username']) ? $request_params['username'] : '';
  // $app_pass = isset($request_params['password']) ? $request_params['password'] : '';

  $args = array();
  if($app_user && $app_pass){
    $args['headers'] = array(
     'Authorization' => 'Basic ' . base64_encode( $app_user . ':' . $app_pass ),
    );
  }

  // $args['timeout'] = 120;
  $response = wp_remote_get( $endpoint, $args);

  $response_code = wp_remote_retrieve_response_code( $response );
  $response_body = wp_remote_retrieve_body( $response );

  $response_data = json_decode($response_body, true);

  $new_formatted_response = array(
    'wc' => $response_data
  );
  return $new_formatted_response;

}

function sd_create_pr_logs($data){

  $post_data = json_decode(file_get_contents('php://input'));
  if(isset($post_data->log) && !empty($post_data->log)){
    $totalcount = count($post_data->log);
    $success_count = 0;
    $failed_count = 0;
    $count = 0;
    $new_acf_data = array();
    $log_data = array();
    foreach ($post_data->log as $object) {
      $unique_id = isset($object->unique_id) ? $object->unique_id : '';
      $ordernumber = isset($object->ordernumber) ? $object->ordernumber : '';
      $import_date = isset($object->import_date) ? $object->import_date : '';
      $object_VchNo = isset($object->VchNo) ? $object->VchNo : '';
      $tally_user_name = isset($object->tally_user_name) ? $object->tally_user_name : '';
      $tally_company_name = isset($object->tally_company_name) ? $object->tally_company_name : '';
      $tally_ref_id = isset($object->tally_ref_id) ? $object->tally_ref_id : '';
      $amount = isset($object->amount) ? $object->amount : '';
      $dr_ledger = isset($object->dr_ledger) ? $object->dr_ledger : '';
      $cr_ledger = isset($object->cr_ledger) ? $object->cr_ledger : '';
      $description = isset($object->description) ? $object->description : '';
      $status = isset($object->status) ? $object->status : '';

      $order_id = $ordernumber;

      if($order_id && wc_get_order($order_id)){

        $log_data = array(
          'import_date' => $import_date,
          'tally_user_name' => $tally_user_name,
          'tally_company_name' => $tally_company_name,
          'tally_ref_id' => $tally_ref_id,
          'ordernumber' => $ordernumber,
          'amount' => $amount,
          'dr_ledger' => $dr_ledger,
          'cr_ledger' => $cr_ledger,
          'description' => $description
        );

        $payment_receipts = get_field(sd_get_order_payment_receipt_field(), $order_id);

        //print_r($payment_receipts); die();

        $update = false;
        if($payment_receipts){
          $ic = 0;
          foreach ($payment_receipts as $pr) {
            $target_group = $pr;
            $acf_unique_id = isset($target_group['unique_id']) ? $target_group['unique_id'] : '';
            $ImportToTally = isset($target_group['rcptImportToTally']) ? $target_group['rcptImportToTally'] : 0;
            if($acf_unique_id && $acf_unique_id == $unique_id && $ImportToTally == 'true'){
              $payment_receipts[$ic]['ImportedToTally'] = $import_date;
              $payment_receipts[$ic]['rcptImportToTally'] = 'false';
              $update = true;
            }
          $ic++;
          }
        }
        if($update){
          update_field(sd_get_order_payment_receipt_field(), $payment_receipts, $order_id);
          update_post_meta($order_id, 'allow_tally_import', '');

          $tally_import_pr_history = get_post_meta($order_id, 'tally_import_pr_history', true) ? get_post_meta($order_id, 'tally_import_pr_history', true) : null;
          $tally_import_pr_history_array = array();
          if($tally_import_pr_history){
            $tally_import_pr_history_array = json_decode($tally_import_pr_history, true);
            $tally_import_pr_history_array[] = $log_data;
          }else{
            $tally_import_pr_history_array[] = $log_data;
          }

          update_post_meta($order_id, 'tally_import_pr_history', json_encode($tally_import_pr_history_array));

          $success_count++;
        }else{
          $failed_count++;
        }

      $count++;

      }
    }

    return array(
      'status' => $success_count ? 'success' : 'error',
      'orders' => $success_count ? 'updated' : 'no orders found',
      'totalcount'  => $totalcount,
      'success_count' => $success_count,
      'failed_count' => $failed_count
    );
  }

}

add_action('admin_head', function(){
?>

<?php
});

add_action('admin_footer', function(){
?>
<script type="text/javascript">
jQuery(document).ready(function($){

  setInterval(function(){
    if($(".acf-field-repeater[data-name=payment_receipt_details]").find("td[data-name=rcptImportToTally] select").length){
      $(".acf-field-repeater[data-name=payment_receipt_details]").find("td[data-name=rcptImportToTally] select").each(function(){
        if(typeof $(this).attr("onclick") === undefined || $(this).attr("onclick") == null){
          $(this).attr("onclick", "sdTrackPrImport(this)");
        }
      });
    }

    if($(".acf-field-repeater[data-name=payment_receipt_details]").find("td[data-name=ImportedToTally] input").length){
      $(".acf-field-repeater[data-name=payment_receipt_details]").find("td[data-name=ImportedToTally] input").each(function(){
        if(typeof $(this).attr("disabled") === undefined || $(this).attr("disabled") == null){
          $(this).attr("disabled", "disabled");
        }
        if($(this).val() != ''){
          $(this).closest("tr").find("td[data-name=rcptImportToTally] select").attr("disabled", "disabled");
        }
      });
    }

  }, 1000);

});

var $ = jQuery.noConflict();

function sdTrackPrImport(e){
  sdTrackOrderImport();
}

function sdTrackOrderImport(){
  if($(".acf-field-repeater[data-name=payment_receipt_details]").find("td[data-name=ImportToTally] input[type=checkbox]").length){
    var trackImport = false
    $(".acf-field-repeater[data-name=payment_receipt_details]").find("td[data-name=ImportToTally] input[type=checkbox]").each(function(){
      if($(this).is(":checked")){
        trackImport = true;
      }
    });
    console.log("Order Import to Tally: "+trackImport);
    if(trackImport){
      $(".acf-field-true-false[data-name=allow_tally_import]").find("input[type=checkbox]").prop("checked", true);
    }
  }
}
</script>
<?php
});


function sd_acf_read_only_field( $field ) {
  if( 'imported_into_tally' === $field['name'] ) {
    $field['disabled'] = true;  
  }
  return $field;
}
add_filter( 'acf/load_field', 'sd_acf_read_only_field' );


add_action( 'add_meta_boxes', 'sd_add_meta_box' );
function sd_add_meta_box( $post_type ) {
  $post_types = array( 'shop_order' );
  if ( in_array( $post_type, $post_types ) ) {
    add_meta_box(
      'sd_tally_import_log',
      __( 'Tally Sales Import Log', 'woocommerce' ),
      'render_sd_tally_import_log',
      $post_type,
      'advanced',
      'high'
    );

    add_meta_box(
      'sd_tally_import_pr_log',
      __( 'Tally Payment Receipts Import Log', 'woocommerce' ),
      'render_sd_tally_import_pr_log',
      $post_type,
      'advanced',
      'high'
    );

    add_meta_box(
      'sd_tally_import_er_log',
      __( 'Tally Expense Receipts Import Log', 'woocommerce' ),
      'render_sd_tally_import_er_log',
      $post_type,
      'advanced',
      'high'
    );
  }
}

function render_sd_tally_import_log($post){
  $tally_import_history = get_post_meta($post->ID, 'tally_import_history', true);
  if($tally_import_history){
  ?>
  <style type="text/css">
  .sd_table table thead tr th{
    text-align: left !important;
  }
  .sd_table table{
    border-collapse: collapse;
  }
  .sd_table table thead tr th, .sd_table table tbody tr td{
    border-collapse: collapse;
    padding: 8px;
  }
  </style>
  <div class="sd_table">
    <table width="100%" border="1" style="overflow-x: scroll;">
      <thead>
        <tr>
          <th>Import Date</th>
          <th>USername</th>
          <th>Company</th>
          <th>Ref ID</th>
          <th>Amount</th>
          <th>DR Ledger</th>
          <th>CR Ledger</th>
          <th>Description</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $tally_import_history_objects = json_decode($tally_import_history);
      foreach ($tally_import_history_objects as $object) {
        $import_date = isset($object->import_date) ? $object->import_date : '';
        $tally_user_name = isset($object->tally_user_name) ? $object->tally_user_name : '';
        $tally_company_name = isset($object->tally_company_name) ? $object->tally_company_name : '';
        $tally_ref_id = isset($object->tally_ref_id) ? $object->tally_ref_id : '';
        $ordernumber = isset($object->ordernumber) ? $object->ordernumber : '';
        $amount = isset($object->amount) ? $object->amount : '';
        $dr_ledger = isset($object->dr_ledger) ? $object->dr_ledger : '';
        $cr_ledger = isset($object->cr_ledger) ? $object->cr_ledger : '';
        $description = isset($object->description) ? $object->description : '';
        $status = isset($object->status) ? $object->status : '';
        ?>
        <tr>
          <td><?php echo $import_date; ?></td>
          <td><?php echo $tally_user_name; ?></td>
          <td><?php echo $tally_company_name; ?></td>
          <td><?php echo $tally_ref_id; ?></td>
          <td><?php echo $amount; ?></td>
          <td><?php echo $dr_ledger; ?></td>
          <td><?php echo $cr_ledger; ?></td>
          <td><?php echo $description; ?></td>
          <td><?php echo $status; ?></td>
        </tr>
        <?php
      }
      ?>
      </tbody>
    </table>
  </div>
  <?php
  }else{
  ?>
  <p>No logs created.</p>
  <?php
  }
}

function render_sd_tally_import_pr_log($post){
  $tally_import_pr_history = get_post_meta($post->ID, 'tally_import_pr_history', true);
  if($tally_import_pr_history){
  ?>
  <style type="text/css">
  .sd_table table thead tr th{
    text-align: left !important;
  }
  .sd_table table{
    border-collapse: collapse;
  }
  .sd_table table thead tr th, .sd_table table tbody tr td{
    border-collapse: collapse;
    padding: 8px;
  }
  </style>
  <div class="sd_table">
    <table width="100%" border="1" style="overflow-x: scroll;">
      <thead>
        <tr>
          <th>Import Date</th>
          <th>USername</th>
          <th>Company</th>
          <th>Ref ID</th>
          <th>Amount</th>
          <th>DR Ledger</th>
          <th>CR Ledger</th>
          <th>Description</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $tally_import_pr_history_objects = json_decode($tally_import_pr_history);
      foreach ($tally_import_pr_history_objects as $object) {
        $import_date = isset($object->import_date) ? $object->import_date : '';
        $tally_user_name = isset($object->tally_user_name) ? $object->tally_user_name : '';
        $tally_company_name = isset($object->tally_company_name) ? $object->tally_company_name : '';
        $tally_ref_id = isset($object->tally_ref_id) ? $object->tally_ref_id : '';
        $ordernumber = isset($object->ordernumber) ? $object->ordernumber : '';
        $amount = isset($object->amount) ? $object->amount : '';
        $dr_ledger = isset($object->dr_ledger) ? $object->dr_ledger : '';
        $cr_ledger = isset($object->cr_ledger) ? $object->cr_ledger : '';
        $description = isset($object->description) ? $object->description : '';
        $status = isset($object->status) ? $object->status : '';
        ?>
        <tr>
          <td><?php echo $import_date; ?></td>
          <td><?php echo $tally_user_name; ?></td>
          <td><?php echo $tally_company_name; ?></td>
          <td><?php echo $tally_ref_id; ?></td>
          <td><?php echo $amount; ?></td>
          <td><?php echo $dr_ledger; ?></td>
          <td><?php echo $cr_ledger; ?></td>
          <td><?php echo $description; ?></td>
          <td><?php echo $status; ?></td>
        </tr>
        <?php
      }
      ?>
      </tbody>
    </table>
  </div>
  <?php
  }else{
  ?>
  <p>No logs created.</p>
  <?php
  }
}

function render_sd_tally_import_er_log($post){
  $tally_import_er_history = get_post_meta($post->ID, 'tally_import_er_history', true);
  if($tally_import_er_history){
  ?>
  <style type="text/css">
  .sd_table table thead tr th{
    text-align: left !important;
  }
  .sd_table table{
    border-collapse: collapse;
  }
  .sd_table table thead tr th, .sd_table table tbody tr td{
    border-collapse: collapse;
    padding: 8px;
  }
  </style>
  <div class="sd_table">
    <table width="100%" border="1" style="overflow-x: scroll;">
      <thead>
        <tr>
          <th>Import Date</th>
          <th>USername</th>
          <th>Company</th>
          <th>Ref ID</th>
          <th>Amount</th>
          <th>DR Ledger</th>
          <th>CR Ledger</th>
          <th>Description</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $tally_import_pr_history_objects = json_decode($tally_import_pr_history);
      foreach ($tally_import_pr_history_objects as $object) {
        $import_date = isset($object->import_date) ? $object->import_date : '';
        $tally_user_name = isset($object->tally_user_name) ? $object->tally_user_name : '';
        $tally_company_name = isset($object->tally_company_name) ? $object->tally_company_name : '';
        $tally_ref_id = isset($object->tally_ref_id) ? $object->tally_ref_id : '';
        $ordernumber = isset($object->ordernumber) ? $object->ordernumber : '';
        $amount = isset($object->amount) ? $object->amount : '';
        $dr_ledger = isset($object->dr_ledger) ? $object->dr_ledger : '';
        $cr_ledger = isset($object->cr_ledger) ? $object->cr_ledger : '';
        $description = isset($object->description) ? $object->description : '';
        $status = isset($object->status) ? $object->status : '';
        ?>
        <tr>
          <td><?php echo $import_date; ?></td>
          <td><?php echo $tally_user_name; ?></td>
          <td><?php echo $tally_company_name; ?></td>
          <td><?php echo $tally_ref_id; ?></td>
          <td><?php echo $amount; ?></td>
          <td><?php echo $dr_ledger; ?></td>
          <td><?php echo $cr_ledger; ?></td>
          <td><?php echo $description; ?></td>
          <td><?php echo $status; ?></td>
        </tr>
        <?php
      }
      ?>
      </tbody>
    </table>
  </div>
  <?php
  }else{
  ?>
  <p>No logs created.</p>
  <?php
  }
}

// Check function exists.
if( function_exists('acf_add_options_page') ) {
  // Register options page.
  $option_page = acf_add_options_page(array(
    'page_title'    => __('SD Options Page'),
    'menu_title'    => __('SD Options Page'),
    'menu_slug'     => 'sd-options-page',
    'capability'    => 'edit_posts',
    'redirect'      => false
  ));
}

function sd_check_same_log_created($saved_log, $new_log){
  $return = false;
  if($saved_log && $new_log){
    foreach ($saved_log as $key => $value) {
      if($value == $new_log){
        $return = true;
      }
    }
  }
  return $return;
}