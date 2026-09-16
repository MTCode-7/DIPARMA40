<?php
interface ProtocolInterface {
    public function getCode(): string;
    public function getName(): string;
    public function execute(array $context): array;
}
