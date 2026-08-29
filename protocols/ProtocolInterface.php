<?php
/**
 * ============================================================
 * واجهة البروتوكول الأساسية
 * ============================================================
 * جميع بروتوكولات المعاملات يجب أن تنفذ هذه الواجهة
 */

interface ProtocolInterface {
    /**
     * الحصول على رمز البروتوكول
     */
    public function getCode(): string;

    /**
     * الحصول على اسم البروتوكول
     */
    public function getName(): string;

    /**
     * تنفيذ البروتوكول
     * 
     * @param array $context السياق والبيانات المطلوبة للتنفيذ
     * @return array النتيجة والحالة
     */
    public function execute(array $context): array;
}
