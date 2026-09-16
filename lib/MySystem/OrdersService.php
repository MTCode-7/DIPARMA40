<?php
/**
 * DI_PARMA_MYSYSTEM | Orders Service
 * Orders are backed by the transactions table and updated after payment results.
 */
class MySystemOrdersService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? db();
    }

    /**
     * Create a pending order (transaction) before provider charge.
     *
     * @param array<string,mixed> $input
     * @param array{id:int,email:string,name:string} $customer
     * @return array{success:bool,order_id?:int,reference:string,message?:string}
     */
    public function create(array $input, array $customer): array
    {
        $reference = trim((string) ($input['reference'] ?? ''));
        if ($reference === '') {
            $reference = function_exists('generateReference')
                ? generateReference('ORD')
                : ('ORD-' . strtoupper(bin2hex(random_bytes(5))));
        }

        $amount = round((float) ($input['amount'] ?? 0), 2);
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'USD')));
        $provider = strtolower(trim((string) ($input['provider'] ?? $input['gateway'] ?? '')));
        $txnType = strtolower(trim((string) ($input['txn_type'] ?? $input['transaction_type'] ?? 'purchase_2d')));

        if ($amount <= 0) {
            return ['success' => false, 'reference' => $reference, 'message' => 'Invalid amount'];
        }

        $payload = [
            'reference' => $reference,
            'user_id' => (int) ($customer['id'] ?? 0),
            'gateway' => $provider !== '' ? $provider : 'pending',
            'amount' => $amount,
            'currency' => $currency,
            'transaction_type' => $txnType,
            'status' => 'pending',
            'cardholder_name' => $customer['name'] ?? ($input['card_name'] ?? null),
            'notes' => (string) ($input['notes'] ?? (($input['channel'] ?? 'mysystem') . '_order')),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $id = $this->db->insertAvailable('transactions', $payload);
            return [
                'success' => true,
                'order_id' => (int) $id,
                'reference' => $reference,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reference' => $reference,
                'message' => 'Order create failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Apply Payment Result back onto the Order.
     *
     * @param array<string,mixed> $result Provider-normalized payment result
     * @return array{success:bool,order?:array<string,mixed>,message?:string}
     */
    public function applyPaymentResult(string $reference, array $result): array
    {
        $reference = trim($reference);
        if ($reference === '') {
            return ['success' => false, 'message' => 'reference required'];
        }

        try {
            $order = $this->db->find('transactions', ['reference' => $reference]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
        if (!$order) {
            return ['success' => false, 'message' => 'Order not found'];
        }

        $ok = !empty($result['success']);
        $status = $ok
            ? (string) ($result['status'] ?? 'completed')
            : (string) ($result['status'] ?? 'failed');
        if (!in_array($status, ['completed', 'authorized', 'pending', 'failed', 'declined', 'requires_3ds'], true)) {
            $status = $ok ? 'completed' : 'failed';
        }

        $gatewayResponse = [
            'source' => 'mysystem_orchestrator',
            'provider' => $result['provider'] ?? ($order['gateway'] ?? null),
            'transaction_id' => $result['transaction_id'] ?? null,
            'approval_code' => $result['approval_code'] ?? null,
            'rrn' => $result['rrn'] ?? null,
            'message' => $result['message'] ?? null,
            'raw_status' => $result['status'] ?? null,
        ];

        $update = [
            'status' => $status === 'authorized' ? 'authorized' : ($ok ? (str_starts_with($status, 'require') ? 'pending' : 'completed') : 'failed'),
            'gateway' => (string) ($result['provider'] ?? $order['gateway'] ?? ''),
            'gateway_response' => json_encode($gatewayResponse, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($result['approval_code'])) {
            $update['auth_code'] = (string) $result['approval_code'];
        }
        if (!empty($result['rrn'])) {
            $update['rrn'] = (string) $result['rrn'];
        }

        try {
            $this->db->update('transactions', $update, ['id' => (int) $order['id']]);
            $fresh = $this->db->find('transactions', ['id' => (int) $order['id']]) ?: array_merge($order, $update);
            return ['success' => true, 'order' => $fresh];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Order update failed: ' . $e->getMessage()];
        }
    }

    public function getByReference(string $reference): ?array
    {
        try {
            return $this->db->find('transactions', ['reference' => trim($reference)]) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
