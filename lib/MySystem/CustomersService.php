<?php
/**
 * DI_PARMA_MYSYSTEM | Customers Service
 * Resolves / loads customer context for payment orchestration.
 */
class MySystemCustomersService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? db();
    }

    /**
     * @return array{id:int,email:string,name:string,phone:string,status:string,kyc_level:string}|null
     */
    public function get(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }
        try {
            $row = $this->db->find('users', ['id' => $customerId]);
        } catch (Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        return $this->normalize($row);
    }

    /**
     * @return array{id:int,email:string,name:string,phone:string,status:string,kyc_level:string}|null
     */
    public function findByEmail(string $email): ?array
    {
        $email = trim(strtolower($email));
        if ($email === '') {
            return null;
        }
        try {
            $row = $this->db->find('users', ['email' => $email]);
        } catch (Throwable $e) {
            return null;
        }
        return $row ? $this->normalize($row) : null;
    }

    /**
     * Resolve customer from request payload / session.
     *
     * @param array<string,mixed> $input
     * @return array{id:int,email:string,name:string,phone:string,status:string,kyc_level:string}
     */
    public function resolve(array $input): array
    {
        $id = (int) ($input['customer_id'] ?? $input['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        if ($id > 0) {
            $c = $this->get($id);
            if ($c) {
                return $c;
            }
        }
        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '') {
            $c = $this->findByEmail($email);
            if ($c) {
                return $c;
            }
        }
        return [
            'id' => $id,
            'email' => $email,
            'name' => trim((string) ($input['card_name'] ?? $input['name'] ?? 'CUSTOMER')),
            'phone' => trim((string) ($input['phone'] ?? '')),
            'status' => 'guest',
            'kyc_level' => 'none',
        ];
    }

    /** @param array<string,mixed> $row */
    private function normalize(array $row): array
    {
        $name = trim((string) ($row['full_name'] ?? $row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'name' => $name !== '' ? $name : 'CUSTOMER',
            'phone' => (string) ($row['phone'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'kyc_level' => (string) ($row['kyc_status'] ?? $row['kyc_level'] ?? 'unknown'),
        ];
    }
}
