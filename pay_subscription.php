<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once ('vendor/stripe/stripe-php/init.php');
require_once ('vendor/stripe/stripe-php/data/StripeConstants.php');

class Stripe_subscription_payment extends API_Controller {
    protected $stripe;

    public function __construct(){

        parent::__construct();

        //for get/post method
        $payment_mode = $this->input->post_get('payment_mode',true) == 'live' ? STRIPE_LIVE_SECRET_KEY : ($this->input->post_get('payment_mode',true) == 'test' ? STRIPE_TEST_SECRET_KEY : 'null');

        //for put/delete method https://codeigniter.com/userguide3/libraries/input.html#using-the-php-input-stream
        if($this->input->input_stream("payment_mode")){ 
            $payment_mode = $this->input->input_stream('payment_mode',true) == 'live' ? STRIPE_LIVE_SECRET_KEY : ($this->input->input_stream('payment_mode',true) == 'test' ? ShTRIPE_TEST_SECRET_KEY : 'null');
        }

        $this->stripe = new \Stripe\StripeClient($payment_mode);

    }


    //Create stripe_customer_id
    public function create_customer_id_post($params = array()) {
        try {
            $customer = $this->stripe->customers->create([
                'description'   => $params["email"],
                'email'         => $params["email"],
                'name'          => $params["first_name"] . " " . $params["last_name"],
            ]);

            return $customer["id"];

        } catch(\Stripe\Exception\ApiErrorException $e) {
            $return_array = [
                "status" => $e->getHttpStatus(),
                "type" => $e->getError()->type,
                "code" => $e->getError()->code,
                "param" => $e->getError()->param,
                "message" => $e->getError()->message,
            ];


            http_response_code($e->getHttpStatus());
            return $this->set_response(["status"=>REST_Controller::HTTP_BAD_REQUEST, "msg"=>$return_array], REST_Controller::HTTP_BAD_REQUEST); //This is the respon if failedecho $return_str;

        }

        
    }


    //Pay for subscription 
    public function pay_subscription_post() {

        $params = $this->input->post();

        if (empty($params)) {
            return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "msg" => 'empty parameter!'], REST_Controller::HTTP_BAD_REQUEST); //This is the response if failed
        }


        // Validation Check
        $validation = new Validator([
            'new_pm_yn'         => $params['new_pm_yn'],
            'payment_method_id' => $params['payment_method_id'],
            'product_price_id'  => $params['product_price_id'],
        ]);

        $validation->rule('required', 'new_pm_yn')->message('empty new_pm_yn ');
        $validation->rule('required', 'payment_method_id')->message('empty payment_method_id');
        $validation->rule('required', 'product_price_id')->message('empty product_price_id');

        if (!$validation->validate()) {
            $errors = getValidationError($validation->errors());
            return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "msg" => $errors["msg"]], REST_Controller::HTTP_BAD_REQUEST); //This is the response if failed
        }
        try {

            //1. Get stripe_customer_id or Create stripe_customer_id if the customer does not have stripe_customer_id
            $stripe_customer_id = !empty($params['stripe_customer_id']) ? $params['stripe_customer_id'] : $this->create_customer_id_post($params);

            //2. Check the PM from the front end is new or not
            //if new pm, attach the pm to the customer
            if ($params['new_pm_yn'] == 'Y') {

                //2a. Retrieve paymentMethod
                $this->retrieve_payment_method($params);


                //2b. Attach PM to customer
                $pm = $this->attach_payment_method($params);

                if($pm){
                    if(!$pm['error']){
                        $pm = $pm['id'];
                    } else{
                        $return_array = [
                            "code"          => $pm['error']['code'],
                            "type"          => $pm['error']['type'],
                            "decline_code"  => $pm['error']['decline_code'],
                            "message"       => $pm['error']['message'],
                        ];
                        return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "msg" => $return_array], REST_Controller::HTTP_BAD_REQUEST);
                    }
                } else {
                    $return_array = [
                        "param"     => $pm,
                        "message"   => 'No PM',
                    ];
                    return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "msg" => $return_array], REST_Controller::HTTP_BAD_REQUEST);
                }

            } else {
                $pm = $params['payment_method_id'];
            }

            $params['pm_id'] = $pm;

            //3. Update the pm as a default payment method
            $this->update_default_payment_method($params);

            //4. Create subscription
            $created_subscription = $this->create_subscription($params);

            return $this->set_response(["status" => REST_Controller::HTTP_OK, "msg" => $created_subscription], REST_Controller::HTTP_OK); //This is the response if success

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $return_array = [
                "status" => $e->getHttpStatus(),
                "type" => $e->getError()->type,
                "code" => $e->getError()->code,
                "param" => $e->getError()->param,
                "message" => $e->getError()->message,
            ];

            http_response_code($e->getHttpStatus());
            return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "msg" => $return_array], REST_Controller::HTTP_BAD_REQUEST); //This is the response if failedecho $return_str;

        }

    }


    //Retrieve payment method
    public function retrieve_payment_method($params)
    {
        $retrieve_pm = $this->stripe->paymentMethods->retrieve(
            $params['payment_method_id'],
            []
        );

        return $retrieve_pm['id'];
    }


    //Attach payment method on the customer
    public function attach_payment_method($params)
    {
        $attached_pm = $this->stripe->paymentMethods->attach(
            $params['payment_method_id'],
            [
                'customer' => $params['stripe_customer_id'],
            ]
        );

        return $attached_pm;
    }


    //Update payment method as a default for subscription
    public function update_default_payment_method($params)
    {
        $updated_default_pm = $this->stripe->customers->update(
            $params['stripe_customer_id'],
            [
                'invoice_settings' => [
                    'default_payment_method' => $params['pm_id'],
                ],
            ]);

        return $updated_default_pm['id'];
    }

    
    //Create Subscription
    public function create_subscription($params)
    {

        //if customer only pays for a product
        if (!empty($params['product_price_id'])) {
            $data = [
                'customer' => $params['stripe_customer_id'],
                'items' => [
                    [
                        'price' => $params['product_price_id'],
                    ],
                ],
            ];
        }

        //if customer also pays for option1 and option2 or either one. (this is for not subscribed item, one time payment at the first time)
        if (!empty($params['option1_price_id']) && !empty($params['option2_price_id'])){

            $data['add_invoice_items'] = [
                [
                    'price' => $params['option1_price_id'],
                ],
                [
                    'price' => $params['option2_price_id'],
                ]
            ];
        } else {
            if (!empty($params['option1_price_id'])) {

                $data['add_invoice_items'] = [
                    [
                        'price' => $params['option1_price_id'],
                    ],
                ];
            }
            else if (!empty($params['option2_price_id'])) {

                $data['add_invoice_items'] = [
                    [
                        'price' => $params['option2_price_id'],
                    ],
                ];
            }
        }

        //if customer has valid promotion code,
        if (!empty($params['promo_id'])) {
            $data['promotion_code'] = $params['promo_id'];
        }

        //if customer is new and free trial period days are provided
        if (!empty($params['trial_period_days'])) {
            $data['trial_period_days'] = $params['trial_period_days'];
        }

        //if the customer scheduled payment later, trial_end should be unix date format.
        if (!empty($params['trial_end'])) {
            $start_date = new DateTime($params['trial_end']);
            $data['trial_end'] = $start_date->format('U');
        }


        //Create subscription
        $subscription = $this->stripe->subscriptions->create(
            $data
        );

        //Get subscription returns
        return $subscription;

    }


    //Cancel on the subscription end date
    public function cancel_subscription_post()
    {

        $params = $this->input->post();

        $sub_id = $params['sub_id'];

        try {

            $subscription_cancel = $this->stripe->subscriptions->update(
                $sub_id,
                [
                    'cancel_at_period_end' => 'true',
                ]
            );

            return $this->set_response(["status" => REST_Controller::HTTP_OK, "msg" => $subscription_cancel], REST_Controller::HTTP_OK);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $return_array = [
                "status" => $e->getHttpStatus(),
                "type" => $e->getError()->type,
                "code" => $e->getError()->code,
                "param" => $e->getError()->param,
                "message" => $e->getError()->message,
            ];

            http_response_code($e->getHttpStatus());
            return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "msg" => $return_array], REST_Controller::HTTP_BAD_REQUEST); //This is the response if failedecho $return_str;

        }
    }


    //Reactive the subscription(unless the subscription is not deleted) 
    public function reactive_subscription_post()
    {

        $params = $this->input->post();

        $sub_id = $params['sub_id'];

        try {

            $subscription_reactive = $this->stripe->subscriptions->update(
                $sub_id,
                [
                    'cancel_at' => '',
                ]
            );

            return $this->set_response(["status" => REST_Controller::HTTP_OK, "msg" => $subscription_reactive], REST_Controller::HTTP_OK);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $return_array = [
                "status" => $e->getHttpStatus(),
                "type" => $e->getError()->type,
                "code" => $e->getError()->code,
                "param" => $e->getError()->param,
                "message" => $e->getError()->message,
            ];

            http_response_code($e->getHttpStatus());
            return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "msg" => $return_array], REST_Controller::HTTP_BAD_REQUEST); //This is the response if failedecho $return_str;

        }

    }


    //Delete immediately
    public function delete_subscription_immediately_delete()
    {

        $sub_id = $this->delete("sub_id");

        try {

            $subscription_delete = $this->stripe->subscriptions->cancel(
                $sub_id,
                []
            );

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $return_array = [
                "status" => $e->getHttpStatus(),
                "type" => $e->getError()->type,
                "code" => $e->getError()->code,
                "param" => $e->getError()->param,
                "message" => $e->getError()->message,
            ];

            http_response_code($e->getHttpStatus());
            return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "msg" => $return_array], REST_Controller::HTTP_BAD_REQUEST); //This is the response if failedecho $return_str;

        }

        return $subscription_delete['id'];
    }


    //Validate promotion code 
    public function validate_promotion_code_get()
    {
        $promo_code = $this->input->get("check_promo_code");

        $promotion = $stripe->promotionCodes->all([
            'code' => $promo_code
        ]);

        if (isset($promotion['data'][0])) {
            if ($promotion['data'][0]['active']) {
                $promotion_id = $promotion['data'][0]['id'];
                $promotion_msg = 'This is a valid promotion code.';
            } else {
                $promotion_id = 'None';
                $promotion_msg = 'This is an invalid promotion code.';
            }

            $data = [
                'promo_id' => $promotion_id,
                'promo_msg' => $promotion_msg,
                'promo_amount' => $promotion['data'][0]['coupon']['amount_off'],
                'promo_percent' => $promotion['data'][0]['coupon']['percent_off'],
            ];

            return $this->set_response(["status" => REST_Controller::HTTP_OK, "data" => $data], REST_Controller::HTTP_OK);
        } else {
            $data = [
                'promo_id' => 'None',
                'promo_msg' => 'This is an invalid promotion code.',
            ];

            return $this->set_response(["status" => REST_Controller::HTTP_BAD_REQUEST, "data" => $data], REST_Controller::HTTP_BAD_REQUEST);
        }

    }

    
    //webhooks
    public function webhooks()
    {

        // Set your secret key. Remember to switch to your live secret key in production.
        // See your keys here: https://dashboard.stripe.com/account/apikeys
        \Stripe\Stripe::setApiKey('sk_test_123456');

        // If you are testing your webhook locally with the Stripe CLI you
        // can find the endpoint's secret by running `stripe listen`
        // Otherwise, find your endpoint's secret in your webhook settings in the Developer Dashboard
        $endpoint_secret = 'whsec_123456';

        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $endpoint_secret
            );
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            exit();
        }

        // Handle the event
        switch ($event->type) {

            case 'invoice.finalized':
                print("invoice.finalized!!!");
                print($event);
                break;
        
            case 'invoice.payment_succeeded':
                print("invoice.payment_succeeded!!!");
                print($event);
                break;
        
            case 'invoice.payment_failed':
        
                print("invoice.payment_failed!!!");
                print($event);
                break;

            case 'invoice.payment_action_required':
                print("invoice.payment_action_required!!!");
                print($event);
                break;
        
            default:
                echo 'Received unknown event type ' . $event->type;
        }

        http_response_code(200);
    }

}