/**
 * ============================================================
 * DI PARMA | ULTIMATE FINANCIAL GATEWAY - MAIN.JS
 * ============================================================
 * الجافا سكريبت الموحد لجميع صفحات النظام
 * Version: 3.1.0 - Enterprise Gold (محدث)
 * ============================================================
 */

(function() {
    'use strict';

    // ============================================================
    // [1] نظام الإشعارات (Toast Notifications)
    // ============================================================
    const Toast = {
        container: null,
        defaults: {
            duration: 5000,
            position: 'top-right',
            type: 'info'
        },

        init: function() {
            if (!this.container) {
                this.container = document.createElement('div');
                this.container.id = 'toast-container';
                this.container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 99999;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                    max-width: 400px;
                    width: 100%;
                    pointer-events: none;
                `;
                document.body.appendChild(this.container);
            }
            return this;
        },

        show: function(message, type = 'info', duration = 5000) {
            this.init();

            const toast = document.createElement('div');
            const icons = {
                success: '✅',
                error: '❌',
                warning: '⚠️',
                info: 'ℹ️'
            };

            const colors = {
                success: '#4CAF50',
                error: '#d9534f',
                warning: '#f0ad4e',
                info: '#5bc0de'
            };

            toast.style.cssText = `
                background: rgba(10, 16, 39, 0.95);
                border: 1px solid ${colors[type] || '#888'};
                border-radius: 12px;
                padding: 14px 20px;
                color: #FFDFA0;
                font-family: 'Cairo', sans-serif;
                font-size: 0.9rem;
                backdrop-filter: blur(18px);
                box-shadow: 0 10px 40px rgba(0,0,0,0.5);
                pointer-events: auto;
                animation: slideInRight 0.4s ease;
                display: flex;
                align-items: center;
                gap: 12px;
                transition: all 0.3s ease;
            `;

            toast.innerHTML = `
                <span style="font-size:1.2rem;">${icons[type] || 'ℹ️'}</span>
                <span style="flex:1;">${message}</span>
                <button onclick="this.parentElement.remove()" style="
                    background: none;
                    border: none;
                    color: #888;
                    cursor: pointer;
                    font-size: 1.2rem;
                    padding: 0 4px;
                ">&times;</button>
            `;

            this.container.appendChild(toast);

            // إزالة تلقائية
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(50px)';
                setTimeout(() => toast.remove(), 400);
            }, duration);

            return this;
        },

        success: function(message, duration) {
            return this.show(message, 'success', duration);
        },

        error: function(message, duration) {
            return this.show(message, 'error', duration);
        },

        warning: function(message, duration) {
            return this.show(message, 'warning', duration);
        },

        info: function(message, duration) {
            return this.show(message, 'info', duration);
        }
    };

    // إضافة أنماط الحركة
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
    `;
    document.head.appendChild(style);

    // ============================================================
    // [2] نظام النسخ (Copy System)
    // ============================================================
    const CopySystem = {
        /**
         * نسخ النص إلى الحافظة
         * @param {string} text - النص المطلوب نسخه
         * @param {string} message - رسالة النجاح
         * @returns {Promise<boolean>}
         */
        copy: async function(text, message = '✅ تم النسخ بنجاح') {
            if (!text || text === '—' || text === '') {
                Toast.warning('⚠️ لا يوجد نص للنسخ');
                return false;
            }

            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(text);
                    Toast.success(message);
                    return true;
                } else {
                    return this.fallbackCopy(text, message);
                }
            } catch (error) {
                return this.fallbackCopy(text, message);
            }
        },

        /**
         * طريقة بديلة للنسخ
         */
        fallbackCopy: function(text, message) {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.style.pointerEvents = 'none';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                Toast.success(message);
                return true;
            } catch (error) {
                Toast.error('❌ فشل في النسخ');
                return false;
            }
        },

        /**
         * إضافة زر نسخ إلى عنصر
         * @param {HTMLElement} element - العنصر المراد إضافة الزر له
         * @param {string} text - النص المراد نسخه
         */
        addCopyButton: function(element, text) {
            if (!element) return;
            
            const btn = document.createElement('button');
            btn.className = 'copy-btn';
            btn.innerHTML = '<i class="fas fa-copy"></i> نسخ';
            btn.type = 'button';
            btn.style.cssText = `
                background: rgba(255,215,0,0.1);
                border: 1px solid rgba(255,215,0,0.2);
                border-radius: 6px;
                padding: 4px 12px;
                color: var(--gold, #FFD700);
                cursor: pointer;
                font-size: 0.75rem;
                font-weight: 700;
                transition: 0.2s;
                margin-left: 8px;
            `;
            btn.onmouseenter = () => {
                btn.style.background = 'rgba(255,215,0,0.2)';
            };
            btn.onmouseleave = () => {
                btn.style.background = 'rgba(255,215,0,0.1)';
            };
            btn.onclick = (e) => {
                e.stopPropagation();
                this.copy(text);
            };
            element.appendChild(btn);
        },

        /**
         * نسخ جميع روابط الدفع في الصفحة
         */
        copyAllLinks: function() {
            const links = document.querySelectorAll('.link-url code, .payment-link-code');
            if (links.length === 0) {
                Toast.warning('⚠️ لا توجد روابط للنسخ');
                return;
            }
            let allText = '';
            links.forEach(link => {
                allText += link.textContent + '\n';
            });
            this.copy(allText, '✅ تم نسخ جميع الروابط (' + links.length + ')');
        }
    };

    // ============================================================
    // [3] نظام إدارة المودالات (Modal Manager)
    // ============================================================
    const ModalManager = {
        activeModals: [],

        /**
         * فتح مودال
         * @param {string|HTMLElement} id - معرف المودال أو العنصر
         */
        open: function(id) {
            const modal = typeof id === 'string' ? document.getElementById(id) : id;
            if (!modal) {
                Toast.error('❌ المودال غير موجود');
                return;
            }

            modal.classList.add('active');
            modal.style.display = 'flex';
            this.activeModals.push(modal);
            document.body.style.overflow = 'hidden';

            // إضافة حدث إغلاق عند الضغط خارج المودال
            const closeHandler = function(e) {
                if (e.target === modal) {
                    ModalManager.close(modal);
                }
            };
            modal.addEventListener('click', closeHandler);
            modal._closeHandler = closeHandler;

            // تشغيل حدث مفتوح
            const event = new CustomEvent('modal:open', { detail: { modal } });
            document.dispatchEvent(event);
        },

        /**
         * إغلاق مودال
         * @param {string|HTMLElement} id - معرف المودال أو العنصر
         */
        close: function(id) {
            const modal = typeof id === 'string' ? document.getElementById(id) : id;
            if (!modal) return;

            modal.classList.remove('active');
            modal.style.display = 'none';
            this.activeModals = this.activeModals.filter(m => m !== modal);

            // إزالة حدث الإغلاق
            if (modal._closeHandler) {
                modal.removeEventListener('click', modal._closeHandler);
                delete modal._closeHandler;
            }

            if (this.activeModals.length === 0) {
                document.body.style.overflow = '';
            }

            // تشغيل حدث مغلق
            const event = new CustomEvent('modal:close', { detail: { modal } });
            document.dispatchEvent(event);
        },

        /**
         * إغلاق جميع المودالات
         */
        closeAll: function() {
            this.activeModals.forEach(modal => {
                modal.classList.remove('active');
                modal.style.display = 'none';
                if (modal._closeHandler) {
                    modal.removeEventListener('click', modal._closeHandler);
                    delete modal._closeHandler;
                }
            });
            this.activeModals = [];
            document.body.style.overflow = '';
        },

        /**
         * تهيئة أزرار إغلاق المودال
         */
        initCloseButtons: function() {
            document.querySelectorAll('.modal .close, .modal-close, [data-modal-close]').forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    if (modal) ModalManager.close(modal);
                });
            });

            // إغلاق بالضغط على Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    ModalManager.closeAll();
                }
            });
        }
    };

    // ============================================================
    // [4] نظام المعاملات (Transaction System)
    // ============================================================
    const TransactionSystem = {
        /**
         * تحديث حالة المعاملة
         * @param {string} reference - المرجع
         * @param {Function} callback - دالة عند النجاح
         */
        updateStatus: async function(reference, callback) {
            if (!reference) {
                Toast.error('❌ المرجع غير صالح');
                return;
            }

            const btn = event?.target?.closest('button');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }

            try {
                const response = await fetch('api/update_transaction.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=check_status&reference=' + encodeURIComponent(reference)
                });

                const data = await response.json();

                if (data.success) {
                    Toast.success('✅ تم تحديث الحالة بنجاح');
                    if (callback) callback(data);
                    
                    // تحديث واجهة المستخدم
                    const statusBadge = document.querySelector(`[data-ref="${reference}"] .status-badge`);
                    if (statusBadge && data.status) {
                        statusBadge.textContent = data.status;
                        statusBadge.className = 'status-badge status-' + data.status;
                    }
                } else {
                    Toast.error('❌ ' + (data.message || 'فشل التحديث'));
                }
            } catch (error) {
                Toast.error('❌ خطأ في الاتصال: ' + error.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-sync"></i>';
                }
            }
        },

        /**
         * تصدير المعاملات إلى CSV
         * @param {Object} filters - عوامل التصفية
         */
        exportCSV: function(filters = {}) {
            const params = new URLSearchParams(filters);
            params.set('export', 'csv');
            window.location.href = 'transactions.php?' + params.toString();
        },

        /**
         * تصدير المعاملات إلى Excel (XLSX)
         */
        exportExcel: function(filters = {}) {
            const params = new URLSearchParams(filters);
            params.set('export', 'excel');
            window.location.href = 'transactions.php?' + params.toString();
        },

        /**
         * البحث عن معاملة
         * @param {string} query - نص البحث
         */
        search: function(query) {
            if (query && query.length > 0) {
                window.location.href = 'transactions.php?search=' + encodeURIComponent(query);
            }
        },

        /**
         * طباعة إيصال المعاملة
         * @param {string} reference - المرجع
         */
        printReceipt: function(reference) {
            if (!reference) {
                Toast.error('❌ المرجع غير صالح');
                return;
            }
            window.open('receipt.php?ref=' + encodeURIComponent(reference) + '&print=1', '_blank');
        }
    };

    // ============================================================
    // [5] نظام البوابات (Gateway System)
    // ============================================================
    const GatewaySystem = {
        /**
         * اختيار بوابة
         * @param {string} code - كود البوابة
         */
        select: function(code) {
            const input = document.getElementById('selectedGateway');
            if (input) input.value = code;

            document.querySelectorAll('.gateway-card').forEach(card => {
                card.classList.remove('selected');
                card.style.borderColor = '';
            });

            document.querySelectorAll('.gateway-card').forEach(card => {
                const name = card.dataset.gatewayCode || card.querySelector('.gw-name')?.textContent?.toLowerCase() || '';
                if (name.includes(code) || name.includes(code.replace('_', ' '))) {
                    card.classList.add('selected');
                    card.style.borderColor = 'var(--gold, #FFD700)';
                    card.style.boxShadow = '0 0 20px rgba(255,215,0,0.15)';
                }
            });

            // تشغيل حدث
            const event = new CustomEvent('gateway:select', { detail: { code } });
            document.dispatchEvent(event);
        },

        /**
         * اختبار اتصال البوابة
         * @param {number|string} id - معرف البوابة أو الكود
         */
        test: async function(id) {
            if (!id) {
                Toast.error('❌ معرف البوابة غير صالح');
                return;
            }

            const btn = document.querySelector(`[data-test-gateway="${id}"]`);
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }

            Toast.info('🔄 جاري اختبار الاتصال...');

            try {
                const formData = new FormData();
                formData.append('action', 'test_connection');
                formData.append('id', id);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    Toast.success('✅ الاتصال ناجح: ' + (data.message || ''));
                    
                    // تحديث حالة الاتصال في الواجهة
                    const statusEl = document.querySelector(`[data-gateway-id="${id}"] .connection-status`);
                    if (statusEl) {
                        statusEl.textContent = '🟢 متصل';
                        statusEl.style.color = '#4CAF50';
                    }
                } else {
                    Toast.error('❌ فشل الاتصال: ' + (data.message || ''));
                    
                    const statusEl = document.querySelector(`[data-gateway-id="${id}"] .connection-status`);
                    if (statusEl) {
                        statusEl.textContent = '🔴 غير متصل';
                        statusEl.style.color = '#d9534f';
                    }
                }
            } catch (error) {
                Toast.error('❌ خطأ: ' + error.message);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-plug"></i> اختبار';
                }
            }
        },

        /**
         * تبديل حالة البوابة
         * @param {number} id - معرف البوابة
         * @param {string} status - الحالة الجديدة
         */
        toggle: async function(id, status) {
            if (!id) return;

            try {
                const formData = new FormData();
                formData.append('action', 'toggle_status');
                formData.append('id', id);
                formData.append('status', status);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    Toast.success('✅ تم تغيير الحالة بنجاح');
                    setTimeout(() => location.reload(), 500);
                } else {
                    Toast.error('❌ ' + (data.message || 'فشل تغيير الحالة'));
                }
            } catch (error) {
                Toast.error('❌ خطأ: ' + error.message);
            }
        },

        /**
         * حذف بوابة
         * @param {number} id - معرف البوابة
         * @param {string} name - اسم البوابة
         */
        delete: async function(id, name) {
            if (!id) return;
            if (!confirm(`⚠️ هل أنت متأكد من حذف بوابة "${name || ''}"؟`)) return;

            try {
                const formData = new FormData();
                formData.append('action', 'delete_gateway');
                formData.append('id', id);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    Toast.success('✅ ' + data.message);
                    setTimeout(() => location.reload(), 500);
                } else {
                    Toast.error('❌ ' + (data.message || 'فشل الحذف'));
                }
            } catch (error) {
                Toast.error('❌ خطأ: ' + error.message);
            }
        },

        /**
         * إضافة بوابة جديدة
         * @param {Object} data - بيانات البوابة
         */
        add: async function(data) {
            try {
                const formData = new FormData();
                for (const [key, value] of Object.entries(data)) {
                    formData.append(key, value);
                }
                formData.append('action', 'add_gateway');

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    Toast.success('✅ تم إضافة البوابة بنجاح');
                    setTimeout(() => location.reload(), 500);
                } else {
                    Toast.error('❌ ' + (result.message || 'فشل الإضافة'));
                }
                return result;
            } catch (error) {
                Toast.error('❌ خطأ: ' + error.message);
                return { success: false, message: error.message };
            }
        }
    };

    // ============================================================
    // [6] نظام روابط الدفع (Payment Links System)
    // ============================================================
    const PaymentLinksSystem = {
        /**
         * نسخ رابط الدفع
         * @param {string} url - رابط الدفع
         * @param {string} message - رسالة النجاح
         */
        copyLink: function(url, message = '✅ تم نسخ رابط الدفع') {
            CopySystem.copy(url, message);
        },

        /**
         * توليد QR Code للرابط
         * @param {string} linkId - معرف الرابط
         * @param {string} token - رمز الأمان
         */
        generateQR: function(linkId, token) {
            if (!linkId) {
                Toast.error('❌ معرف الرابط غير صالح');
                return;
            }

            const url = window.location.origin + '/pay.php?link=' + linkId + (token ? '&token=' + token : '');
            const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(url);
            window.open(qrUrl, '_blank');
        },

        /**
         * تمديد صلاحية الرابط
         * @param {number} id - معرف الرابط
         */
        extend: async function(id) {
            if (!id) return;

            const days = prompt('أدخل عدد الأيام للتمديد:', '7');
            if (!days || isNaN(days) || days <= 0) return;

            try {
                const formData = new FormData();
                formData.append('action', 'extend');
                formData.append('id', id);
                formData.append('days', days);

                const response = await fetch('api/extend_link.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    Toast.success('✅ تم تمديد الرابط بنجاح!');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    Toast.error('❌ ' + (data.message || 'فشل التمديد'));
                }
            } catch (error) {
                Toast.error('❌ خطأ: ' + error.message);
            }
        },

        /**
         * حذف رابط
         * @param {number} id - معرف الرابط
         * @param {string} title - عنوان الرابط
         */
        delete: function(id, title) {
            if (!id) return;
            if (!confirm(`⚠️ هل أنت متأكد من حذف الرابط "${title || ''}"؟`)) return;

            const token = document.querySelector('input[name="csrf_token"]')?.value || '';
            window.location.href = 'links.php?delete=' + id + '&token=' + token;
        },

        /**
         * تعطيل/تفعيل رابط
         * @param {number} id - معرف الرابط
         * @param {string} status - الحالة الجديدة
         */
        toggle: function(id, status) {
            if (!id) return;
            const token = document.querySelector('input[name="csrf_token"]')?.value || '';
            window.location.href = 'links.php?toggle=' + id + '&status=' + status + '&token=' + token;
        },

        /**
         * إنشاء رابط دفع جديد
         * @param {Object} data - بيانات الرابط
         */
        create: async function(data) {
            try {
                const formData = new FormData();
                for (const [key, value] of Object.entries(data)) {
                    formData.append(key, value);
                }
                formData.append('action', 'create');

                const response = await fetch('api/create_link.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    Toast.success('✅ تم إنشاء الرابط بنجاح');
                    return result;
                } else {
                    Toast.error('❌ ' + (result.message || 'فشل الإنشاء'));
                    return null;
                }
            } catch (error) {
                Toast.error('❌ خطأ: ' + error.message);
                return null;
            }
        }
    };

    // ============================================================
    // [7] نظام تنسيق البطاقة (Card Formatter)
    // ============================================================
    const CardFormatter = {
        /**
         * تنسيق رقم البطاقة
         * @param {HTMLInputElement} input - حقل الإدخال
         */
        formatCardNumber: function(input) {
            let val = input.value.replace(/\D/g, '').slice(0, 16);
            const formatted = val.replace(/(.{4})/g, '$1 ').trim();
            input.value = formatted;
            
            // تحديث نوع البطاقة
            const type = this.detectCardType(val);
            const typeDisplay = input.closest('.card-area')?.querySelector('.card-type-display');
            if (typeDisplay) {
                const icons = {
                    'Visa': '💳 Visa',
                    'Mastercard': '💳 Mastercard',
                    'Amex': '💳 Amex',
                    'Discover': '💳 Discover',
                    'Diners Club': '💳 Diners',
                    'JCB': '💳 JCB',
                    'Unknown': '💳 Card'
                };
                typeDisplay.textContent = icons[type] || icons['Unknown'];
            }
        },

        /**
         * تنسيق تاريخ الانتهاء
         * @param {HTMLInputElement} input - حقل الإدخال
         */
        formatExpiry: function(input) {
            let val = input.value.replace(/\D/g, '').slice(0, 4);
            if (val.length >= 2) {
                let month = parseInt(val.slice(0, 2));
                if (month > 12) val = '12' + val.slice(2);
                if (val.length > 2) val = val.slice(0, 2) + '/' + val.slice(2);
            }
            input.value = val;
            
            // التحقق من صحة التاريخ
            if (val.length === 5) {
                const isValid = this.isValidExpiry(val);
                const expiryDisplay = input.closest('.card-area')?.querySelector('.expiry-status');
                if (expiryDisplay) {
                    expiryDisplay.textContent = isValid ? '✅' : '❌';
                    expiryDisplay.style.color = isValid ? '#4CAF50' : '#d9534f';
                }
            }
        },

        /**
         * تنسيق رمز الأمان (CVV)
         * @param {HTMLInputElement} input - حقل الإدخال
         */
        formatCVV: function(input) {
            input.value = input.value.replace(/\D/g, '').slice(0, 4);
        },

        /**
         * التعرف على نوع البطاقة من الرقم
         * @param {string} number - رقم البطاقة
         * @returns {string} - نوع البطاقة
         */
        detectCardType: function(number) {
            const clean = number.replace(/\D/g, '');
            if (clean.startsWith('4')) return 'Visa';
            if (clean.match(/^5[1-5]/)) return 'Mastercard';
            if (clean.match(/^3[47]/)) return 'Amex';
            if (clean.match(/^6(?:011|5)/)) return 'Discover';
            if (clean.match(/^3(?:0[0-5]|[68])/)) return 'Diners Club';
            if (clean.match(/^(?:2131|1800|35)/)) return 'JCB';
            if (clean.startsWith('62')) return 'UnionPay';
            return 'Unknown';
        },

        /**
         * إخفاء رقم البطاقة
         * @param {string} number - رقم البطاقة
         * @returns {string} - رقم مخفي
         */
        maskCardNumber: function(number) {
            const clean = number.replace(/\D/g, '');
            const length = clean.length;
            if (length < 10) return '****';
            const visible = 4;
            const masked = '*'.repeat(Math.min(length - visible, 12)) + clean.slice(-visible);
            return masked.replace(/(.{4})/g, '$1 ').trim();
        },

        /**
         * التحقق من صحة رقم البطاقة (خوارزمية Luhn)
         * @param {string} number - رقم البطاقة
         * @returns {boolean}
         */
        isValidLuhn: function(number) {
            const clean = number.replace(/\D/g, '');
            if (clean.length < 13 || clean.length > 19) return false;

            let sum = 0;
            let alternate = false;
            for (let i = clean.length - 1; i >= 0; i--) {
                let n = parseInt(clean[i]);
                if (alternate) {
                    n *= 2;
                    if (n > 9) n -= 9;
                }
                sum += n;
                alternate = !alternate;
            }
            return sum % 10 === 0;
        },

        /**
         * التحقق من صحة تاريخ الانتهاء
         * @param {string} expiry - تاريخ الانتهاء (MM/YY)
         * @returns {boolean}
         */
        isValidExpiry: function(expiry) {
            const match = expiry.match(/^(0[1-9]|1[0-2])\/([0-9]{2})$/);
            if (!match) return false;

            const month = parseInt(match[1]);
            const year = parseInt(match[2]) + 2000;
            const now = new Date();
            const currentYear = now.getFullYear();
            const currentMonth = now.getMonth() + 1;

            if (year < currentYear) return false;
            if (year === currentYear && month < currentMonth) return false;
            return true;
        },

        /**
         * التحقق من صحة رمز الأمان (CVV)
         * @param {string} cvv - رمز الأمان
         * @param {string} cardType - نوع البطاقة
         * @returns {boolean}
         */
        isValidCVV: function(cvv, cardType = 'Visa') {
            const clean = cvv.replace(/\D/g, '');
            const length = cardType === 'Amex' ? 4 : 3;
            return clean.length === length && /^\d+$/.test(clean);
        }
    };

    // ============================================================
    // [8] نظام NFC (Near Field Communication)
    // ============================================================
    const NFCSystem = {
        supported: false,
        reader: null,
        isScanning: false,
        scanTimeout: null,

        /**
         * التحقق من دعم NFC
         * @returns {boolean}
         */
        checkSupport: function() {
            this.supported = 'NDEFReader' in window && window.isSecureContext;
            return this.supported;
        },

        /**
         * بدء مسح NFC
         * @param {Function} onSuccess - دالة عند النجاح
         * @param {Function} onError - دالة عند الخطأ
         */
        startScan: async function(onSuccess, onError) {
            if (!this.checkSupport()) {
                const msg = 'NFC غير مدعوم في هذا المتصفح (يلزم HTTPS و Chrome/Edge)';
                if (onError) onError(msg);
                Toast.warning('⚠️ ' + msg);
                return;
            }

            if (this.isScanning) {
                Toast.warning('⚠️ جاري المسح بالفعل');
                return;
            }

            try {
                this.isScanning = true;
                this.reader = new NDEFReader();
                await this.reader.scan();

                Toast.info('📡 قرب بطاقتك من القارئ...');

                // وقت المهلة
                this.scanTimeout = setTimeout(() => {
                    if (this.isScanning) {
                        this.stopScan();
                        Toast.warning('⏰ انتهت مهلة المسح');
                        if (onError) onError('Scan timeout');
                    }
                }, 30000);

                this.reader.addEventListener('reading', ({ message, serialNumber }) => {
                    clearTimeout(this.scanTimeout);
                    
                    let cardData = {
                        serial: serialNumber,
                        records: [],
                        pan: null,
                        expiry: null,
                        name: null
                    };

                    for (const record of message.records) {
                        if (record.type === 'text') {
                            try {
                                const textDecoder = new TextDecoder(record.encoding || 'utf-8');
                                const text = textDecoder.decode(record.data);
                                cardData.records.push({ type: 'text', data: text });

                                // محاولة استخراج رقم البطاقة
                                const cardMatch = text.match(/\b\d{13,19}\b/);
                                if (cardMatch) cardData.pan = cardMatch[0];

                                // محاولة استخراج تاريخ الانتهاء
                                const expiryMatch = text.match(/\b(0[1-9]|1[0-2])\/([0-9]{2})\b/);
                                if (expiryMatch) cardData.expiry = expiryMatch[0];

                                // محاولة استخراج الاسم
                                const nameMatch = text.match(/[A-Z][a-z]+ [A-Z][a-z]+/);
                                if (nameMatch) cardData.name = nameMatch[0];
                            } catch (e) {
                                // تجاهل
                            }
                        } else if (record.type === 'url') {
                            try {
                                const url = new TextDecoder().decode(record.data);
                                cardData.records.push({ type: 'url', data: url });
                            } catch (e) {}
                        }
                    }

                    Toast.success('✅ تم قراءة البطاقة بنجاح');

                    if (onSuccess) onSuccess(cardData);
                    this.stopScan();
                });

                this.reader.addEventListener('error', (error) => {
                    clearTimeout(this.scanTimeout);
                    const msg = error.message || 'خطأ في القراءة';
                    Toast.error('❌ ' + msg);
                    if (onError) onError(error);
                    this.stopScan();
                });

            } catch (error) {
                this.isScanning = false;
                const msg = error.message || 'فشل بدء المسح';
                Toast.error('❌ ' + msg);
                if (onError) onError(error);
            }
        },

        /**
         * إيقاف المسح
         */
        stopScan: function() {
            this.isScanning = false;
            clearTimeout(this.scanTimeout);
            if (this.reader) {
                try {
                    this.reader.removeEventListener('reading');
                    this.reader.removeEventListener('error');
                } catch (e) {
                    // تجاهل
                }
                this.reader = null;
            }
            Toast.info('🔄 تم إيقاف المسح');
        },

        /**
         * ملء حقول البطاقة بالبيانات المقروءة
         * @param {Object} data - بيانات البطاقة
         */
        fillCardFields: function(data) {
            if (data.pan) {
                const panInput = document.getElementById('cardPan') || document.querySelector('[name="card_number"]');
                if (panInput) {
                    panInput.value = data.pan.replace(/(.{4})/g, '$1 ').trim();
                    panInput.dispatchEvent(new Event('input'));
                    panInput.dispatchEvent(new Event('change'));
                }
            }

            if (data.expiry) {
                const expiryInput = document.getElementById('cardExpiry') || document.querySelector('[name="card_expiry"]');
                if (expiryInput) {
                    expiryInput.value = data.expiry;
                    expiryInput.dispatchEvent(new Event('input'));
                }
            }

            if (data.name) {
                const nameInput = document.getElementById('cardName') || document.querySelector('[name="card_name"]');
                if (nameInput) {
                    nameInput.value = data.name;
                    nameInput.dispatchEvent(new Event('input'));
                }
            }

            // عرض نوع البطاقة
            if (data.pan) {
                const type = CardFormatter.detectCardType(data.pan);
                const typeDisplay = document.getElementById('cardTypeDisplay');
                if (typeDisplay) {
                    const icons = {
                        'Visa': '🟦',
                        'Mastercard': '🟧',
                        'Amex': '🟩',
                        'Discover': '🟪',
                        'Unknown': '🟨'
                    };
                    typeDisplay.textContent = (icons[type] || '🟨') + ' ' + type;
                }
            }
        },

        /**
         * كتابة بيانات إلى بطاقة NFC
         * @param {Object} data - البيانات المراد كتابتها
         */
        write: async function(data) {
            if (!this.checkSupport()) {
                Toast.warning('⚠️ NFC غير مدعوم');
                return false;
            }

            try {
                const reader = new NDEFReader();
                await reader.write({
                    records: [
                        {
                            recordType: 'text',
                            data: data.text || JSON.stringify(data)
                        }
                    ]
                });
                Toast.success('✅ تم كتابة البيانات بنجاح');
                return true;
            } catch (error) {
                Toast.error('❌ فشل الكتابة: ' + error.message);
                return false;
            }
        }
    };

    // ============================================================
    // [9] نظام الحسابات (Calculations)
    // ============================================================
    const Calculator = {
        exchangeRates: {},
        gasFees: {},

        /**
         * تحديث أسعار الصرف
         * @param {Object} rates - أسعار الصرف
         */
        updateRates: function(rates) {
            this.exchangeRates = rates || {};
        },

        /**
         * تحديث رسوم الغاز
         * @param {Object} fees - رسوم الغاز
         */
        updateGasFees: function(fees) {
            this.gasFees = fees || {};
        },

        /**
         * جلب أسعار الصرف من API
         */
        fetchRates: async function() {
            try {
                const response = await fetch('https://api.exchangerate-api.com/v4/latest/USD');
                const data = await response.json();
                if (data.rates) {
                    this.updateRates(data.rates);
                }
            } catch (error) {
                console.warn('[Calculator] Failed to fetch rates:', error);
            }
        },

        /**
         * حساب الرسوم والصافي
         * @param {Object} data - بيانات الحساب
         * @returns {Object} - النتائج
         */
        calculateFees: function(data) {
            const amount = parseFloat(data.amount) || 0;
            const currency = data.currency || 'USD';
            const operational = parseFloat(data.operationalFee) || 0;
            const contract = parseFloat(data.contractFee) || 0;
            const gasNetwork = data.gasNetwork || 'TRC20';
            const additionalFees = parseFloat(data.additionalFees) || 0;

            const rate = this.exchangeRates[currency] || 1;
            const gasUSD = this.gasFees[gasNetwork] || 0.8;
            const gasFee = gasUSD / rate;

            const opAmount = (amount * operational) / 100;
            const contractAmount = (amount * contract) / 100;
            const totalFees = opAmount + contractAmount + gasFee + additionalFees;
            const netAmount = amount - totalFees;
            const netUSDT = netAmount * rate;

            return {
                gross: amount,
                operationalFee: opAmount,
                contractFee: contractAmount,
                gasFee: gasFee,
                additionalFees: additionalFees,
                totalFees: totalFees,
                netAmount: netAmount,
                netUSDT: netUSDT,
                currency: currency,
                gasNetwork: gasNetwork,
                rate: rate,
                feePercentage: ((totalFees / amount) * 100) || 0
            };
        },

        /**
         * توزيع المبلغ على المحافظ
         * @param {number} amount - المبلغ
         * @param {Array} wallets - المحافظ
         * @returns {Array} - التوزيع
         */
        distributeWallets: function(amount, wallets) {
            const totalPercent = wallets.reduce((sum, w) => sum + (parseFloat(w.percent) || 0), 0);

            if (totalPercent !== 100) {
                Toast.warning('⚠️ إجمالي النسب يجب أن يساوي 100% (حالياً ' + totalPercent + '%)');
                return wallets.map(w => ({ ...w, distributedAmount: 0 }));
            }

            return wallets.map(w => {
                const percent = parseFloat(w.percent) || 0;
                return {
                    ...w,
                    distributedAmount: (amount * percent) / 100,
                    distributedUSDT: ((amount * percent) / 100) * (this.exchangeRates[w.currency] || 1)
                };
            });
        },

        /**
         * تحديث عرض صافي المبلغ
         * @param {Object} data - بيانات الحساب
         * @param {string} displayId - معرف العنصر
         */
        updateNetDisplay: function(data, displayId = 'netAmountDisplay') {
            const result = this.calculateFees(data);
            const display = document.getElementById(displayId);
            if (display) {
                display.textContent = result.netAmount.toFixed(6) + ' ' + data.currency + ' → ' + result.netUSDT.toFixed(6) + ' USDT';
            }
            return result;
        }
    };

    // ============================================================
    // [10] نظام الصفحات (Pagination System)
    // ============================================================
    const Pagination = {
        /**
         * تحديث ترقيم الصفحات
         * @param {number} currentPage - الصفحة الحالية
         * @param {number} totalPages - إجمالي الصفحات
         * @param {string} containerId - معرف الحاوية
         */
        render: function(currentPage, totalPages, containerId = 'pagination') {
            const container = document.getElementById(containerId);
            if (!container) return;

            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            let html = '';
            const urlParams = new URLSearchParams(window.location.search);

            // زر السابق
            if (currentPage > 1) {
                urlParams.set('page', currentPage - 1);
                html += `<a href="?${urlParams.toString()}" class="page-link prev"><i class="fas fa-chevron-right"></i></a>`;
            } else {
                html += `<span class="page-link prev disabled"><i class="fas fa-chevron-right"></i></span>`;
            }

            // أرقام الصفحات
            const startPage = Math.max(1, currentPage - 2);
            const endPage = Math.min(totalPages, currentPage + 2);

            // الصفحة الأولى
            if (startPage > 1) {
                urlParams.set('page', 1);
                html += `<a href="?${urlParams.toString()}" class="page-link">1</a>`;
                if (startPage > 2) html += `<span class="page-link dots">…</span>`;
            }

            for (let i = startPage; i <= endPage; i++) {
                urlParams.set('page', i);
                html += `<a href="?${urlParams.toString()}" class="page-link ${i === currentPage ? 'active' : ''}">${i}</a>`;
            }

            // الصفحة الأخيرة
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += `<span class="page-link dots">…</span>`;
                urlParams.set('page', totalPages);
                html += `<a href="?${urlParams.toString()}" class="page-link">${totalPages}</a>`;
            }

            // زر التالي
            if (currentPage < totalPages) {
                urlParams.set('page', currentPage + 1);
                html += `<a href="?${urlParams.toString()}" class="page-link next"><i class="fas fa-chevron-left"></i></a>`;
            } else {
                html += `<span class="page-link next disabled"><i class="fas fa-chevron-left"></i></span>`;
            }

            // إضافة عدد النتائج
            const totalItems = container.dataset.total || 0;
            if (totalItems) {
                html += `<span class="page-info">${totalItems} نتيجة</span>`;
            }

            container.innerHTML = html;

            // إضافة أنماط CSS
            const style = document.createElement('style');
            style.textContent = `
                #${containerId} {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 6px;
                    flex-wrap: wrap;
                    padding: 10px 0;
                }
                #${containerId} .page-link {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    min-width: 36px;
                    height: 36px;
                    padding: 0 10px;
                    border-radius: 8px;
                    background: rgba(255,255,255,0.05);
                    border: 1px solid rgba(255,255,255,0.08);
                    color: var(--text, #edf0f7);
                    text-decoration: none;
                    font-size: 0.85rem;
                    font-weight: 600;
                    transition: 0.2s;
                }
                #${containerId} .page-link:hover {
                    background: rgba(255,215,0,0.1);
                    border-color: var(--gold, #FFD700);
                }
                #${containerId} .page-link.active {
                    background: var(--gold, #FFD700);
                    color: #000;
                    border-color: var(--gold, #FFD700);
                }
                #${containerId} .page-link.disabled {
                    opacity: 0.3;
                    cursor: not-allowed;
                    pointer-events: none;
                }
                #${containerId} .page-link.dots {
                    border: none;
                    background: none;
                    cursor: default;
                }
                #${containerId} .page-info {
                    font-size: 0.75rem;
                    color: var(--muted2, #6b7a90);
                    margin-left: 12px;
                }
            `;
            document.head.appendChild(style);
        }
    };

    // ============================================================
    // [11] أدوات عامة (Utilities)
    // ============================================================
    const Utils = {
        /**
         * توليد معرف فريد
         * @param {number} length - طول المعرف
         * @returns {string}
         */
        generateId: function(length = 8) {
            return Math.random().toString(36).substr(2, length);
        },

        /**
         * تنسيق التاريخ
         * @param {string|Date} date - التاريخ
         * @param {string} format - صيغة العرض
         * @returns {string}
         */
        formatDate: function(date, format = 'dd/mm/yyyy HH:MM') {
            const d = typeof date === 'string' ? new Date(date) : date;
            if (!(d instanceof Date) || isNaN(d)) return '';

            const pad = (n) => String(n).padStart(2, '0');

            const replacements = {
                'dd': pad(d.getDate()),
                'mm': pad(d.getMonth() + 1),
                'yyyy': d.getFullYear(),
                'yy': String(d.getFullYear()).slice(-2),
                'HH': pad(d.getHours()),
                'MM': pad(d.getMinutes()),
                'SS': pad(d.getSeconds())
            };

            let result = format;
            for (const [key, value] of Object.entries(replacements)) {
                result = result.replace(key, value);
            }
            return result;
        },

        /**
         * تنسيق التاريخ بالعربية
         */
        formatDateArabic: function(date) {
            const d = typeof date === 'string' ? new Date(date) : date;
            if (!(d instanceof Date) || isNaN(d)) return '';

            const months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
            const day = d.getDate();
            const month = months[d.getMonth()];
            const year = d.getFullYear();
            return day + ' ' + month + ' ' + year;
        },

        /**
         * التحقق من صحة البريد الإلكتروني
         * @param {string} email - البريد الإلكتروني
         * @returns {boolean}
         */
        isValidEmail: function(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        /**
         * التحقق من صحة رقم الهاتف
         * @param {string} phone - رقم الهاتف
         * @returns {boolean}
         */
        isValidPhone: function(phone) {
            return /^\+?[0-9]{10,15}$/.test(phone.replace(/\s/g, ''));
        },

        /**
         * التحقق من صحة رقم البطاقة (Luhn)
         * @param {string} number - رقم البطاقة
         * @returns {boolean}
         */
        isValidCardNumber: function(number) {
            return CardFormatter.isValidLuhn(number);
        },

        /**
         * التحقق من صحة تاريخ الانتهاء
         * @param {string} expiry - تاريخ الانتهاء (MM/YY)
         * @returns {boolean}
         */
        isValidExpiry: function(expiry) {
            return CardFormatter.isValidExpiry(expiry);
        },

        /**
         * التحقق من صحة رمز الأمان (CVV)
         * @param {string} cvv - رمز الأمان
         * @param {string} cardType - نوع البطاقة
         * @returns {boolean}
         */
        isValidCVV: function(cvv, cardType = 'Visa') {
            return CardFormatter.isValidCVV(cvv, cardType);
        },

        /**
         * اختصار النص
         * @param {string} text - النص
         * @param {number} maxLength - الحد الأقصى
         * @returns {string}
         */
        truncate: function(text, maxLength = 50) {
            if (!text) return '';
            if (text.length <= maxLength) return text;
            return text.slice(0, maxLength) + '...';
        },

        /**
         * تحويل النص إلى Slug
         * @param {string} text - النص
         * @returns {string}
         */
        slugify: function(text) {
            return text
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9-]/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
        },

        /**
         * تحويل النص إلى حروف كبيرة مع تنسيق
         */
        capitalize: function(text) {
            return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
        },

        /**
         * إزالة HTML من النص
         */
        stripHTML: function(text) {
            const doc = new DOMParser().parseFromString(text, 'text/html');
            return doc.body.textContent || '';
        },

        /**
         * الحصول على معامل من URL
         */
        getParam: function(name) {
            const url = new URL(window.location.href);
            return url.searchParams.get(name);
        },

        /**
         * إضافة معامل إلى URL
         */
        setParam: function(name, value) {
            const url = new URL(window.location.href);
            url.searchParams.set(name, value);
            window.history.pushState({}, '', url);
        },

        /**
         * نسخ كائن عميق
         */
        deepClone: function(obj) {
            return JSON.parse(JSON.stringify(obj));
        },

        /**
         * انتظار لمدة محددة
         */
        sleep: function(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }
    };

    // ============================================================
    // [12] تهيئة الصفحة (Initialize)
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        // تهيئة المودالات
        ModalManager.initCloseButtons();

        // إضافة أحداث تنسيق البطاقة
        document.querySelectorAll('[data-card-format]').forEach(input => {
            input.addEventListener('input', function() {
                const format = this.dataset.cardFormat;
                if (format === 'card-number') CardFormatter.formatCardNumber(this);
                else if (format === 'expiry') CardFormatter.formatExpiry(this);
                else if (format === 'cvv') CardFormatter.formatCVV(this);
            });
        });

        // تهيئة أزرار النسخ
        document.querySelectorAll('[data-copy]').forEach(btn => {
            btn.addEventListener('click', function() {
                const text = this.dataset.copy;
                CopySystem.copy(text, this.dataset.message || '✅ تم النسخ');
            });
        });

        // تهيئة أزرار المودال
        document.querySelectorAll('[data-modal-open]').forEach(btn => {
            btn.addEventListener('click', function() {
                const modalId = this.dataset.modalOpen;
                ModalManager.open(modalId);
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach(btn => {
            btn.addEventListener('click', function() {
                const modalId = this.dataset.modalClose;
                ModalManager.close(modalId);
            });
        });

        // تهيئة أزرار تحديث الحالة
        document.querySelectorAll('[data-update-status]').forEach(btn => {
            btn.addEventListener('click', function() {
                const ref = this.dataset.updateStatus;
                TransactionSystem.updateStatus(ref);
            });
        });

        // تهيئة أزرار اختبار البوابة
        document.querySelectorAll('[data-test-gateway]').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.testGateway;
                GatewaySystem.test(id);
            });
        });

        // تهيئة أزرار اختيار البوابة
        document.querySelectorAll('.gateway-card').forEach(card => {
            card.addEventListener('click', function() {
                const code = this.dataset.gatewayCode || this.querySelector('.gw-name')?.textContent?.toLowerCase() || '';
                GatewaySystem.select(code);
            });
        });

        // تهيئة نظام المحافظ
        document.querySelectorAll('[name^="wallet_percent_"]').forEach(input => {
            input.addEventListener('input', window.updatePercent);
        });

        // تهيئة حساب الرسوم
        document.querySelectorAll('[data-calculate]').forEach(input => {
            input.addEventListener('input', window.calculateFees);
        });

        // تهيئة NFC
        const nfcBtn = document.getElementById('nfcScanBtn');
        if (nfcBtn) {
            nfcBtn.addEventListener('click', function() {
                NFCSystem.startScan(
                    function(data) {
                        NFCSystem.fillCardFields(data);
                        Toast.success('✅ تم قراءة البطاقة بنجاح');
                    },
                    function(error) {
                        console.error('NFC Error:', error);
                    }
                );
            });
        }

        // إغلاق الشريط الجانبي عند النقر خارجها
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.sidebar-toggle');
            if (sidebar && toggle && sidebar.classList.contains('open')) {
                if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    window.closeSidebar();
                }
            }
        });

        // جلب أسعار الصرف
        Calculator.fetchRates();

        console.log('🟢 DI PARMA System initialized successfully');
        console.log('📊 Version: 3.1.0 - Enterprise Gold');
        console.log('📦 Systems loaded: Toast, Copy, Modal, Transaction, Gateway, PaymentLinks, CardFormatter, NFC, Calculator, Pagination, Utils');
    });

    // ============================================================
    // [13] دوال عامة للاستخدام في HTML (Global Functions)
    // ============================================================

    /**
     * نسخ النص
     */
    window.copyText = function(text, message) {
        return CopySystem.copy(text, message);
    };

    /**
     * فتح مودال
     */
    window.openModal = function(id) {
        ModalManager.open(id);
    };

    /**
     * إغلاق مودال
     */
    window.closeModal = function(id) {
        ModalManager.close(id);
    };

    /**
     * فتح/إغلاق الشريط الجانبي
     */
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.toggle('open');
        if (overlay) overlay.classList.toggle('visible');
    };

    /**
     * إغلاق الشريط الجانبي
     */
    window.closeSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('visible');
    };

    /**
     * اختيار بوابة
     */
    window.selectGateway = function(code) {
        GatewaySystem.select(code);
    };

    /**
     * تنسيق رقم البطاقة
     */
    window.formatCard = function(input) {
        CardFormatter.formatCardNumber(input);
    };

    /**
     * تنسيق تاريخ الانتهاء
     */
    window.formatExpiry = function(input) {
        CardFormatter.formatExpiry(input);
    };

    /**
     * تنسيق رمز الأمان
     */
    window.formatCVV = function(input) {
        CardFormatter.formatCVV(input);
    };

    /**
     * إضافة محفظة
     */
    window.addWallet = function() {
        const container = document.getElementById('walletsContainer');
        if (!container) {
            Toast.warning('⚠️ لا يوجد حاوية للمحافظ');
            return;
        }

        const wallets = container.querySelectorAll('.wallet-row');
        const count = wallets.length + 1;
        if (count > 6) {
            Toast.warning('⚠️ الحد الأقصى 6 محافظ');
            return;
        }

        const row = document.createElement('div');
        row.className = 'wallet-row';
        row.style.cssText = `
            display: grid;
            grid-template-columns: auto 1fr 1fr 1fr 1fr;
            gap: 10px;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        `;
        row.innerHTML = `
            <strong style="color:var(--text,#edf0f7);font-size:0.8rem;">${count}</strong>
            <select name="wallet_network_${count}" style="
                background:rgba(255,255,255,0.05);
                border:1px solid rgba(255,255,255,0.1);
                border-radius:8px;
                padding:6px 10px;
                color:var(--text,#edf0f7);
                font-family:'Cairo',sans-serif;
            ">
                <option value="TRC20">TRC20 (Tron)</option>
                <option value="ERC20">ERC20 (Ethereum)</option>
                <option value="BEP20">BEP20 (BSC)</option>
                <option value="SOL">SOL (Solana)</option>
                <option value="BTC">BTC (Bitcoin)</option>
                <option value="XRP">XRP (Ripple)</option>
            </select>
            <select name="wallet_currency_${count}" style="
                background:rgba(255,255,255,0.05);
                border:1px solid rgba(255,255,255,0.1);
                border-radius:8px;
                padding:6px 10px;
                color:var(--text,#edf0f7);
                font-family:'Cairo',sans-serif;
            ">
                <option value="USDT">USDT</option>
                <option value="BTC">BTC</option>
                <option value="ETH">ETH</option>
                <option value="USDC">USDC</option>
                <option value="BNB">BNB</option>
                <option value="XRP">XRP</option>
                <option value="SOL">SOL</option>
            </select>
            <input type="number" name="wallet_percent_${count}" value="0" min="0" max="100" 
                placeholder="%" oninput="window.updatePercent()" style="
                background:rgba(255,255,255,0.05);
                border:1px solid rgba(255,255,255,0.1);
                border-radius:8px;
                padding:6px 10px;
                color:var(--text,#edf0f7);
                width:70px;
            ">
            <input type="text" name="wallet_address_${count}" placeholder="أدخل عنوان المحفظة" style="
                background:rgba(255,255,255,0.05);
                border:1px solid rgba(255,255,255,0.1);
                border-radius:8px;
                padding:6px 10px;
                color:var(--text,#edf0f7);
                font-family:'Cairo',sans-serif;
            ">
            <button onclick="this.closest('.wallet-row').remove();window.updatePercent();" style="
                background:none;
                border:none;
                color:#d9534f;
                cursor:pointer;
                font-size:1.1rem;
            "><i class="fas fa-times"></i></button>
        `;
        container.appendChild(row);
        window.updatePercent();
    };

    /**
     * تحديث النسب المئوية للمحافظ
     */
    window.updatePercent = function() {
        let total = 0;
        document.querySelectorAll('[name^="wallet_percent_"]').forEach(el => {
            total += parseFloat(el.value) || 0;
        });
        const display = document.getElementById('totalPercent');
        if (display) {
            display.textContent = Math.round(total);
            display.style.color = Math.round(total) === 100 ? '#4CAF50' : '#d9534f';
        }
    };

    /**
     * حساب الرسوم
     */
    window.calculateFees = function() {
        const amount = document.getElementById('amount');
        const currency = document.getElementById('currency');
        const operationalFee = document.getElementById('operationalFee');
        const contractFee = document.getElementById('contractFee');
        const gasNetwork = document.getElementById('gasNetwork');

        if (!amount || !currency) return;

        const data = {
            amount: amount.value,
            currency: currency.value,
            operationalFee: operationalFee?.value || 2.5,
            contractFee: contractFee?.value || 1.0,
            gasNetwork: gasNetwork?.value || 'TRC20'
        };

        Calculator.updateNetDisplay(data);
    };

    /**
     * بدء مسح NFC
     */
    window.startNFCScan = function() {
        NFCSystem.startScan(
            function(data) {
                NFCSystem.fillCardFields(data);
                Toast.success('✅ تم قراءة البطاقة بنجاح');
            },
            function(error) {
                Toast.error('❌ خطأ في NFC: ' + (error.message || 'غير معروف'));
            }
        );
    };

    /**
     * إيقاف مسح NFC
     */
    window.stopNFCScan = function() {
        NFCSystem.stopScan();
    };

    /**
     * نسخ رابط الدفع
     */
    window.copyLink = function(url, message) {
        PaymentLinksSystem.copyLink(url, message);
    };

    /**
     * توليد QR Code
     */
    window.generateQR = function(linkId, token) {
        PaymentLinksSystem.generateQR(linkId, token);
    };

    /**
     * تمديد صلاحية الرابط
     */
    window.extendLink = function(id) {
        PaymentLinksSystem.extend(id);
    };

    /**
     * حذف الرابط
     */
    window.deleteLink = function(id, title) {
        PaymentLinksSystem.delete(id, title);
    };

    /**
     * تبديل حالة الرابط
     */
    window.toggleLink = function(id, status) {
        PaymentLinksSystem.toggle(id, status);
    };

    /**
     * تحديث حالة المعاملة
     */
    window.updateTransactionStatus = function(reference) {
        TransactionSystem.updateStatus(reference);
    };

    /**
     * اختبار اتصال البوابة
     */
    window.testGateway = function(id) {
        GatewaySystem.test(id);
    };

    /**
     * تبديل حالة البوابة
     */
    window.toggleGateway = function(id, status) {
        GatewaySystem.toggle(id, status);
    };

    /**
     * حذف البوابة
     */
    window.deleteGateway = function(id, name) {
        GatewaySystem.delete(id, name);
    };

    /**
     * عرض إشعار
     */
    window.showToast = function(message, type = 'info', duration = 5000) {
        Toast.show(message, type, duration);
    };

    /**
     * تصدير المعاملات إلى CSV
     */
    window.exportTransactionsCSV = function(filters = {}) {
        TransactionSystem.exportCSV(filters);
    };

    /**
     * تصدير المعاملات إلى Excel
     */
    window.exportTransactionsExcel = function(filters = {}) {
        TransactionSystem.exportExcel(filters);
    };

    /**
     * طباعة الإيصال
     */
    window.printReceipt = function(reference) {
        TransactionSystem.printReceipt(reference);
    };

    /**
     * جلب أسعار الصرف
     */
    window.fetchExchangeRates = function() {
        return Calculator.fetchRates();
    };

    /**
     * تنسيق التاريخ بالعربية
     */
    window.formatDateArabic = function(date) {
        return Utils.formatDateArabic(date);
    };

    console.log('🟢 DI PARMA Global Functions loaded');
    console.log('📦 Available functions:', Object.keys(window).filter(k => 
        typeof window[k] === 'function' && ['copyText', 'openModal', 'closeModal', 'toggleSidebar', 'closeSidebar', 'selectGateway', 'formatCard', 'formatExpiry', 'formatCVV', 'addWallet', 'updatePercent', 'calculateFees', 'startNFCScan', 'stopNFCScan', 'copyLink', 'generateQR', 'extendLink', 'deleteLink', 'toggleLink', 'updateTransactionStatus', 'testGateway', 'toggleGateway', 'deleteGateway', 'showToast', 'exportTransactionsCSV', 'exportTransactionsExcel', 'printReceipt', 'fetchExchangeRates', 'formatDateArabic'].includes(k)
    ));

})();