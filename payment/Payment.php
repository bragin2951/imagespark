<?php

namespace App\Libraries;

use App\Models\Shop\Order as ShopOrder;
use App\Models\Webinar\Order as WebinarOrder;
use App\Models\Payment as PaymentModel;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use YandexCheckout\Client;

class Payment
{
    private $client;
    private $order;
    private $data;

    public function __construct($shopId, $secretKey, $setOrder = true)
    {
        $this->client = new Client();
        $this->client->setAuth($shopId, $secretKey);
        if ($setOrder) {
            $this->setOrder();
        }
    }

    public function new()
    {
        $this->data = [];
        return $this;
    }

    public static function getOrderFront()
    {
        if (!Session::has('payment')) {
            abort(404);
        }
        $data = Crypt::decrypt(Session::get('payment'));
        if (!isset($data['type'])) {
            abort(404);
        }
        switch ($data['type']) {
            case 'shop':
                $order = ShopOrder::findOrFail($data['order']);
                $data = new \stdClass();
                $data->id = $order->id;
                $data->total = $order->actualTotal;
                return $data;
                break;
            case 'webinar':
                $order = WebinarOrder::findOrFail($data['order']);
                $data = new \stdClass();
                $data->id = $order->id;
                $data->total = $order->total;
                return $data;
                break;
            default:
                abort(404);
        }
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function setOrder($id = null, $type = 'shop')
    {
        if ($id) {
            switch ($type) {
                case 'shop':
                    $this->order = ShopOrder::findOrFail($id);
                    break;
                case 'webinar':
                    $this->order = WebinarOrder::findOrFail($id);
                    break;
                default:
                    $this->order = ShopOrder::findOrFail($id);
                    return $this;
                    break;
            }
        } else {
            if (!Session::has('payment')) {
                return;
            }
            $data = Crypt::decrypt(Session::get('payment'));
            if (!isset($data['type'])) {
                return;
            }
            switch ($data['type']) {
                case 'shop':
                    $this->order = ShopOrder::findOrFail($data['order']);
                    $id = $data['order'];
                    $type = 'shop';
                    break;
                case 'webinar':
                    $this->order = WebinarOrder::findOrFail($data['order']);
                    $id = $data['order'];
                    $type = 'webinar';
                    break;
                default:
                    break;
            }
        }
        $this->setDescription();
        $this->setAmount();
        $this->setReceipt();
        $this->setMetadata(compact('id', 'type'));
    }

    public function setPaymentToken($paymentToken)
    {
        $this->data['payment_token'] = $paymentToken;
        return $this;
    }

    public function setPaymentMethodID($paymentMethodID)
    {
        $this->data['payment_method_id'] = $paymentMethodID;
        return $this;
    }

    public function setPaymentMethodData($type)
    {
        $this->data['payment_method_data'] = [
            'type' => $type
        ];
        return $this;
    }

    public function setConfirmation($type, $returnUrl = null)
    {
        if (is_null($returnUrl)) {
            $returnUrl = 'https://payments.'.env('APP_DOMAIN', 'kanitelka.org').'/return-url';
        }
        $this->data['confirmation'] = [
            'type' => $type,
            'return_url' => $returnUrl
        ];
        return $this;
    }

    public function savePaymentMethod()
    {
        $this->data['save_payment_method'] = true;
        return $this;
    }

    public function test()
    {
        $this->data['test'] = true;
        return $this;
    }

    public function capture()
    {
        $this->data['capture'] = true;
        return $this;
    }

    public function pay()
    {
        $idempotenceKey = $this->generateIdempotenceKey();
        $payment = $this->client->createPayment($this->data, $idempotenceKey);
        Session::put('paymentID', $payment->id);
        $this->createPayment($payment);
        $confirmation = $payment->getConfirmation();
        if ($confirmation) {
            $returnUrl = $confirmation->confirmation_url;
        } else {
            $returnUrl = $this->data['confirmation']['return_url'];
        }
        return $returnUrl;
    }

    public function capturePayment($paymentID)
    {
        $paymentModel = PaymentModel::query()
            ->where('id', $paymentID)
            ->first();

        $idempotenceKey = $this->generateIdempotenceKey();
        $this->client->capturePayment(
            array(
                'amount' => $paymentModel->amount,
            ),
            $paymentModel->id,
            $idempotenceKey
        );

        if ($paymentModel) {
            $paymentModel->setPaid(true);
            $paymentModel->setStatus('succeeded');
            $paymentModel->setCapturedAt(date('Y-m-d H:i:s'));
            $paymentModel->item->setStatus('pending');
        }

    }

    public function get($paymentID)
    {
        return $this->client->getPaymentInfo($paymentID);
    }

    public function cancelPayment($payment)
    {
        $idempotenceKey = $this->generateIdempotenceKey();
        $this->client->cancelPayment(
            $payment->id,
            $idempotenceKey
        );

        $paymentModel = PaymentModel::query()
            ->where('id', $payment->id)
            ->first();

        if ($paymentModel) {
            $paymentModel->setPaid(false);
            $paymentModel->setStatus('canceled');
            $paymentModel->item->setStatus('pending');
        }
    }

    public function repeatPayment()
    {
        $idempotenceKey = $this->generateIdempotenceKey();
        $payment = $this->client->createPayment($this->data, $idempotenceKey);
        Session::put('paymentID', $payment->id);
        $this->createPayment($payment);
    }

    private function setDescription($description = null)
    {
        $order = $this->getOrder();
        if (is_null($description)) {
            $description = 'Оплата заказа #'.$order->id;
        }
        $this->data['description'] = $description;
    }

    private function setAmount($value = null, $currency = 'RUB')
    {
        $order = $this->getOrder();
        if (is_null($value)) {
            $value = $order->actualTotal.'.00';
        }
        $this->data['amount'] = compact('value', 'currency');
    }

    private function setReceipt()
    {
        $order = $this->getOrder();
        if (Auth::check()) {
            $email = Auth::user()->email;
        } else {
            $email = $order->customerDetails->email;
        }
        $this->data['receipt'] = [
            'items' => $order->getItems(),
            'tax_system_code' => intval(env('YANDEX_KASSA_TAX_SYSTEM_CODE', 0)),
            'email' => $email
        ];
    }

    private function generateIdempotenceKey()
    {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            // 32 bits for "time_low"
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

            // 16 bits for "time_mid"
            mt_rand( 0, 0xffff ),

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand( 0, 0x0fff ) | 0x4000,

            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand( 0, 0x3fff ) | 0x8000,

            // 48 bits for "node"
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }

    private function setMetadata($data = [])
    {
        $this->data['metadata'] = $data;
    }

    private function createPayment($payment)
    {
        $paymentMethod = PaymentMethod::query()
            ->where('id', $payment->getPaymentMethod()->getId())
            ->first();

        if (!$paymentMethod) {
            $paymentMethod = new PaymentMethod([
                'id' => $payment->getPaymentMethod()->getId(),
                'type' => $payment->getPaymentMethod()->getType(),
                'saved' => $payment->getPaymentMethod()->getSaved(),
                'title' => $payment->getPaymentMethod()->getTitle()
            ]);
            switch($payment->getPaymentMethod()->getType()) {
                case 'sberbank':
                    $paymentMethod->data_name = 'phone';
                    $paymentMethod->data_value = $payment->getPaymentMethod()->getPhone();
                    break;
                case 'bank_card':
                    $paymentMethod->data_name = 'card';
                    $paymentMethod->data_value = json_encode([
                        'last4' => $payment->getPaymentMethod()->getLast4(),
                        'expiry_month' => $payment->getPaymentMethod()->getExpiryMonth(),
                        'expiry_year' => $payment->getPaymentMethod()->getExpiryYear(),
                        'card_type' => $payment->getPaymentMethod()->getCardType()
                    ]);
                    break;
                case 'yandex_money':
                    $paymentMethod->data_name = 'account_number';
                    $paymentMethod->data_value = $payment->getPaymentMethod()->getAccountNumber();
                    break;
                case 'alfabank':
                    $paymentMethod->data_name = 'login';
                    $paymentMethod->data_value = $payment->getPaymentMethod()->getLogin();
                    break;
            }
            $paymentMethod->save();
        }

        $paymentModel = new PaymentModel([
            'id' => $payment->getId(),
            'status' => $payment->getStatus(),
            'amount' => json_encode($payment->getAmount()),
            'created_at' => $payment->getCreatedAt()->format('Y-m-d H:i:s'),
            'test' => (isset($payment->test)) ? $payment->test : false,
            'paid' => (isset($payment->paid)) ? $payment->getPaid() : false
        ]);

        if (isset($payment->description)) {
            $paymentModel->description = $payment->getDescription();
        }

        if (!is_null($payment->captured_at)) {
            $paymentModel->captured_at = $payment->getCapturedAt()->format('Y-m-d H:i:s');
        }

        if (!is_null($payment->expires_at)) {
            $paymentModel->expires_at = $payment->getExpiresAt()->format('Y-m-d H:i:s');
        }

        if (!is_null($payment->refunded_amount)) {
            $paymentModel->refunded_amount = json_encode($payment->getRefundedAmount());
        }

        if (!is_null($payment->receipt_registration)) {
            $paymentModel->receipt_registration = $payment->getReceiptRegistration();
        }

        $paymentModel->payment_method_id = $paymentMethod->id;

        $this->order->payments()->save($paymentModel);
    }
}