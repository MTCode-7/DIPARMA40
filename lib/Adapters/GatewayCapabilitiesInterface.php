<?php
declare(strict_types=1);

/**
 * DI PARMA | GatewayCapabilitiesInterface
 * واجهة قدرات البوابة — تُطبَّق اختيارياً من الـ Adapters
 * لتوفير معلومات تفصيلية عن أوضاع الدفع المدعومة.
 */
interface GatewayCapabilitiesInterface
{
    /** أوضاع الدفع التي يدعمها الكود (2D, 3D, MOTO, OFFLINE...) */
    public function supportedModes(): array;

    /** أوضاع الدفع المفعّلة فعلياً للحساب الحالي */
    public function enabledModes(): array;

    /** هل الوضع مدعوم بالكود؟ */
    public function supportsMode(string $mode): bool;

    /** هل الوضع مفعّل للحساب؟ */
    public function isModeEnabled(string $mode): bool;

    /** العمليات المدعومة (CHARGE, HOLD, CAPTURE, CANCEL, REFUND...) */
    public function supportedOperations(): array;

    /** العملات المقبولة */
    public function supportedCurrencies(): array;

    /** مصفوفة: عملية → أوضاع مسموحة */
    public function operationModeMatrix(): array;

    /** لقطة كاملة لكل قدرات البوابة */
    public function capabilitiesSnapshot(): array;
}
