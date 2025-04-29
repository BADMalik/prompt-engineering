<?php
interface PaymentGatewayInterface
{
    public function charge(float $amount, string $currency, string $token, string $email, array $metadata = []): PaymentResponse;
    public function refund(string $transactionId): PaymentResponse;
}

// --- Payment Response Object ---
class PaymentResponse
{
    public bool $success;
    public string $message;
    public ?string $transactionId;

    public function __construct(bool $success, string $message, ?string $transactionId = null)
    {
        $this->success = $success;
        $this->message = $message;
        $this->transactionId = $transactionId;
    }
}

// --- Stripe Gateway ---
class StripePaymentGateway implements PaymentGatewayInterface
{
    private bool $isLiveMode;

    public function __construct(bool $isLive = false)
    {
        $this->isLiveMode = $isLive;
    }

    public function charge(float $amount, string $currency, string $token, string $email, array $metadata = []): PaymentResponse
    {
        try {
            Validator::validatePayment($amount, $currency, $email);

            $convertedAmount = CurrencyConverter::convertToStripeSupported($amount, $currency);
            $transactionId = 'txn_' . uniqid();

            echo "Charging {$convertedAmount} {$currency} via Stripe for {$email}...\n";
            echo "Metadata: " . json_encode($metadata) . "\n";
            echo "Mode: " . ($this->isLiveMode ? 'LIVE' : 'TEST') . "\n";

            // Simulate 1 retry if failed
            $success = false;
            for ($i = 0; $i < 2; $i++) {
                $success = rand(0, 1);
                if ($success) break;
                Logger::log("Retry attempt $i for payment: {$transactionId}");
            }

            $message = $success ? Translator::translate("Payment success", "en") : Translator::translate("Payment failed", "en");
            Logger::log("Stripe charge for {$email} => {$message}");

            return new PaymentResponse($success, $message, $success ? $transactionId : null);
        } catch (Exception $e) {
            Logger::log("Charge error: " . $e->getMessage());
            return new PaymentResponse(false, $e->getMessage());
        }
    }

    public function refund(string $transactionId): PaymentResponse
    {
        $success = rand(0, 1);
        $message = $success ? "Refund successful for $transactionId" : "Refund failed for $transactionId";
        Logger::log($message);
        return new PaymentResponse($success, $message, $transactionId);
    }
}

// --- Logger ---
class Logger
{
    public static function log(string $message): void
    {
        $log = "[" . date("Y-m-d H:i:s") . "] $message\n";
        file_put_contents('payment_log.txt', $log, FILE_APPEND);
    }
}

// --- Currency Converter ---
class CurrencyConverter
{
    public static function convertToStripeSupported(float $amount, string $currency): float
    {
        if ($currency === 'EUR') return $amount * 11;
        return $amount;
    }
}

// --- Validator ---
class Validator
{
    public static function validatePayment(float $amount, string $currency, string $email): void
    {
        if ($amount <= 0) throw new Exception("Amount must be > 0.");
        if (!in_array($currency, ['USD', 'EUR'])) throw new Exception("Unsupported currency.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email.");
    }
}

// --- Translator (Multi-language Support) ---
class Translator
{
    public static function translate(string $message, string $lang): string
    {
        $translations = [
            'Payment success' => ['fr' => 'Paiement réussi'],
            'Payment failed' => ['fr' => 'Échec du paiement']
        ];
        return $translations[$message][$lang] ?? $message;
    }
}

// --- Webhook Simulator ---
class WebhookHandler
{
    public static function simulateEvent(string $transactionId, string $event): void
    {
        echo "Received webhook for {$event} on {$transactionId}\n";
        Logger::log("Webhook event received: $event for $transactionId");
    }
}

// --- Checkout Service ---
class CheckoutService
{
    private PaymentGatewayInterface $gateway;

    public function __construct(PaymentGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function processPayment(float $amount, string $currency, string $token, string $email, array $metadata = [])
    {
        $response = $this->gateway->charge($amount, $currency, $token, $email, $metadata);
        echo $response->message . "\n";
        if ($response->transactionId) {
            WebhookHandler::simulateEvent($response->transactionId, 'charge.succeeded');
        }
    }

    public function processRefund(string $transactionId)
    {
        $response = $this->gateway->refund($transactionId);
        echo $response->message . "\n";
    }
}

class SimpleTestFramework
{
    private $testCount = 0;
    private $passCount = 0;
    private $failCount = 0;
    private $startTime;

    // ANSI color codes
    const COLOR_GREEN = "\033[32m";
    const COLOR_RED = "\033[31m";
    const COLOR_YELLOW = "\033[33m";
    const COLOR_BLUE = "\033[34m";
    const COLOR_RESET = "\033[0m";
    const COLOR_BOLD = "\033[1m";

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function run($testName, $callable)
    {
        $this->testCount++;
        echo "Running test: " . self::COLOR_BLUE . $testName . self::COLOR_RESET . "... ";

        try {
            $callable($this);
            echo self::COLOR_GREEN . "PASSED" . self::COLOR_RESET . "\n";
            $this->passCount++;
        } catch (Exception $e) {
            echo self::COLOR_RED . "FAILED: " . $e->getMessage() . self::COLOR_RESET . "\n";
            $this->failCount++;
        }
    }

    public function assertEqual($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertTrue($condition, $message = 'Expected true, got false')
    {
        if ($condition !== true) {
            throw new Exception($message);
        }
    }

    public function assertNull($value, $message = 'Expected null value')
    {
        if ($value !== null) {
            throw new Exception($message . ': ' . $this->formatValue($value));
        }
    }

    public function assertFalse($condition, $message = 'Expected false, got true')
    {
        if ($condition !== false) {
            throw new Exception($message);
        }
    }

    public function assertNotNull($value, $message = 'Expected non-null value')
    {
        if ($value === null) {
            throw new Exception($message);
        }
    }

    public function assertFloatEquals($expected, $actual, float $tolerance = 0.001, $message = '')
    {
        if (abs($expected - $actual) > $tolerance) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected approximately $expected, got $actual";
            throw new Exception($details);
        }
    }

    public function assertGreaterThan($expected, $actual, $message = '')
    {
        if ($actual <= $expected) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected value greater than " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertLessThan($expected, $actual, $message = '')
    {
        if ($actual >= $expected) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected value less than " . $this->formatValue($expected) . ", got " . $this->formatValue($actual);
            throw new Exception($details);
        }
    }

    public function assertCount($expectedCount, $array, $message = '')
    {
        $actualCount = count($array);
        if ($expectedCount !== $actualCount) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected count $expectedCount, got $actualCount";
            throw new Exception($details);
        }
    }

    public function assertEmpty($value, $message = 'Expected empty value')
    {
        if (!empty($value)) {
            throw new Exception($message);
        }
    }

    public function assertNotEmpty($value, $message = 'Expected non-empty value')
    {
        if (empty($value)) {
            throw new Exception($message);
        }
    }

    public function assertContains($needle, $haystack, $message = '')
    {
        if (!in_array($needle, $haystack)) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected array to contain " . $this->formatValue($needle);
            throw new Exception($details);
        }
    }

    public function assertStringContains($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) === false) {
            $details = $message ? $message . ': ' : '';
            $details .= "Expected string to contain " . $this->formatValue($needle);
            throw new Exception($details);
        }
    }

    private function formatValue($value)
    {
        if (is_array($value)) {
            return 'Array(' . count($value) . ')';
        } elseif (is_object($value)) {
            return get_class($value);
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            return 'null';
        } else {
            return (string)$value;
        }
    }

    public function summary()
    {
        $duration = microtime(true) - $this->startTime;
        echo "\n" . self::COLOR_BOLD . "==== Test Summary ====" . self::COLOR_RESET . "\n";
        echo "Total tests: " . self::COLOR_BOLD . $this->testCount . self::COLOR_RESET . "\n";
        echo "Passed: " . self::COLOR_GREEN . $this->passCount . self::COLOR_RESET . "\n";

        if ($this->failCount > 0) {
            echo "Failed: " . self::COLOR_RED . $this->failCount . self::COLOR_RESET . "\n";
        } else {
            echo "Failed: " . $this->failCount . "\n";
        }

        echo "Time: " . self::COLOR_YELLOW . round($duration, 2) . " seconds" . self::COLOR_RESET . "\n";
        echo self::COLOR_BOLD . "======================" . self::COLOR_RESET . "\n";

        if ($this->failCount === 0) {
            echo self::COLOR_GREEN . "All tests passed successfully!" . self::COLOR_RESET . "\n";
        } else {
            echo self::COLOR_RED . "Some tests failed. Please review the output above." . self::COLOR_RESET . "\n";
        }

        return $this->failCount === 0;
    }
}

$tester = new SimpleTestFramework();

$tester->run('Webhook Simulation', function ($test) {
    $transactionId = 'txn_webhook_123';
    $event = 'charge.succeeded';

    // Clear the log file before the test
    file_put_contents('payment_log.txt', '');

    WebhookHandler::simulateEvent($transactionId, $event);

    $logContent = file_get_contents('payment_log.txt');
    $test->assertStringContains("Webhook event received: $event for $transactionId", $logContent, "Log message should contain the webhook event details");

    // Clear the log file for next tests
    file_put_contents('payment_log.txt', '');
});

$tester->run('Checkout Service Integration', function ($test) {
    $gateway = new StripePaymentGateway(false);
    $checkout = new CheckoutService($gateway);

    // Clear the log file before the test
    file_put_contents('payment_log.txt', '');

    $checkout->processPayment(
        amount: 75.50,
        currency: 'EUR',
        token: 'tok_test_789',
        email: 'client@example.com',
        metadata: ['orderId' => 555, 'customerId' => 'C1001']
    );

    $logContent = file_get_contents('payment_log.txt');
    $test->assertStringContains('Webhook event received: charge.succeeded', $logContent, "Log message should contain the webhook event details for successful charge");

    // Clear the log file before the test
    file_put_contents('payment_log.txt', '');

    $checkout->processRefund('txn_abc_456');

    $logContent = file_get_contents('payment_log.txt');
    $test->assertStringContains('Refund successful for txn_abc_456', $logContent, "Log message should contain the refund success message");

    // Clear the log file for next tests
    file_put_contents('payment_log.txt', '');
});

$tester->summary();
