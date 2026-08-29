/**
 * ============================================================
 * DI PARMA | Performance JS — تحسينات الأداء الشاملة
 * ============================================================
 * 
 * الميزات:
 * 1. Lazy Image Loading - تحميل الصور البطيء
 * 2. Debounce - منع التنفيذ المتكرر
 * 3. Throttle - تحديد معدل التنفيذ
 * 4. Idle Callback - تنفيذ المهام في أوقات الفراغ
 * 5. Prefetch Links - تحميل مسبق للروابط
 * 6. Performance Badge - عرض أداء الصفحة
 * 7. Form Submit - منع الإرسال المزدوج
 * 8. Session Heartbeat - الحفاظ على الجلسة
 * 9. Lazy Loading for IFrames - تحميل الإطارات البطيء
 * 10. Virtual Scrolling - تمرير افتراضي للقوائم الطويلة
 * 11. Memory Management - إدارة الذاكرة
 * 12. Network Detection - كشف سرعة الشبكة
 * 
 * Version: 2.0.0 - Enterprise Gold
 * ============================================================
 */

(function() {
    'use strict';

    // ============================================================
    // [1] Lazy Image Loading - تحميل الصور البطيء
    // ============================================================
    function initLazyImages() {
        if (!('IntersectionObserver' in window)) {
            // Fallback: تحميل الصور فوراً
            document.querySelectorAll('img[data-src]').forEach(img => {
                img.src = img.dataset.src;
                img.classList.add('loaded');
            });
            return;
        }

        const imageObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const img = entry.target;
                
                // تحميل الصورة
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.onload = () => {
                        img.classList.add('loaded');
                        // تشغيل حدث مخصص
                        img.dispatchEvent(new CustomEvent('image:loaded', { detail: { img } }));
                    };
                    img.onerror = () => {
                        img.classList.add('error');
                        // عرض صورة بديلة
                        if (img.dataset.fallback) {
                            img.src = img.dataset.fallback;
                        }
                    };
                    delete img.dataset.src;
                }
                
                // تحميل srcset
                if (img.dataset.srcset) {
                    img.srcset = img.dataset.srcset;
                    delete img.dataset.srcset;
                }
                
                imageObserver.unobserve(img);
            });
        }, {
            rootMargin: '200px 0px',
            threshold: 0.01
        });

        document.querySelectorAll('img[data-src], img[data-srcset]').forEach(img => {
            imageObserver.observe(img);
        });
    }

    // ============================================================
    // [2] Debounce - منع التنفيذ المتكرر
    // ============================================================
    /**
     * منع التنفيذ المتكرر لدالة (تنفيذ آخر مرة فقط)
     * @param {Function} fn - الدالة المراد تنفيذها
     * @param {number} ms - وقت الانتظار بالمللي ثانية
     * @param {boolean} immediate - تنفيذ أول مرة فوراً
     * @returns {Function}
     */
    function debounce(fn, ms = 300, immediate = false) {
        let timeoutId;
        let lastArgs = null;
        let lastThis = null;
        
        return function(...args) {
            lastArgs = args;
            lastThis = this;
            
            const callNow = immediate && !timeoutId;
            
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                timeoutId = null;
                if (!immediate) {
                    fn.apply(lastThis, lastArgs);
                }
            }, ms);
            
            if (callNow) {
                fn.apply(lastThis, lastArgs);
            }
        };
    }

    // ============================================================
    // [3] Throttle - تحديد معدل التنفيذ
    // ============================================================
    /**
     * تحديد معدل تنفيذ الدالة (تنفيذ مرة كل فترة)
     * @param {Function} fn - الدالة المراد تنفيذها
     * @param {number} ms - الفترة الزمنية بالمللي ثانية
     * @param {Object} options - خيارات إضافية
     * @returns {Function}
     */
    function throttle(fn, ms = 100, options = { leading: true, trailing: true }) {
        let lastCall = 0;
        let timeoutId = null;
        let lastArgs = null;
        let lastThis = null;
        
        return function(...args) {
            const now = Date.now();
            const remaining = ms - (now - lastCall);
            
            lastArgs = args;
            lastThis = this;
            
            if (remaining <= 0 || remaining > ms) {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                    timeoutId = null;
                }
                if (options.leading !== false) {
                    lastCall = now;
                    fn.apply(lastThis, lastArgs);
                }
                return;
            }
            
            if (options.trailing !== false && !timeoutId) {
                timeoutId = setTimeout(() => {
                    timeoutId = null;
                    lastCall = Date.now();
                    fn.apply(lastThis, lastArgs);
                }, remaining);
            }
        };
    }

    // ============================================================
    // [4] Idle Callback - تنفيذ المهام في أوقات الفراغ
    // ============================================================
    /**
     * تنفيذ مهمة في وقت الفراغ (لا تؤثر على الأداء)
     * @param {Function} fn - الدالة المراد تنفيذها
     * @param {Object} options - خيارات إضافية
     * @returns {number|NodeJS.Timeout}
     */
    function idleCallback(fn, options = {}) {
        if (window.requestIdleCallback) {
            return window.requestIdleCallback(fn, options);
        }
        // Polyfill: تنفيذ بعد 1ms
        return setTimeout(fn, 1);
    }

    // ============================================================
    // [5] Prefetch Links - تحميل مسبق للروابط
    // ============================================================
    function initPrefetch() {
        const prefetched = new Set();
        let currentHref = null;
        
        // استخدام IntersectionObserver للروابط المرئية
        if ('IntersectionObserver' in window) {
            const linkObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    const link = entry.target;
                    const href = link.href;
                    
                    if (!href || !href.startsWith(window.location.origin)) return;
                    if (prefetched.has(href)) return;
                    if (href === window.location.href) return;
                    
                    prefetched.add(href);
                    
                    // استخدام Prefetch أو Preconnect
                    const linkTag = document.createElement('link');
                    linkTag.rel = 'prefetch';
                    linkTag.href = href;
                    linkTag.as = 'document';
                    document.head.appendChild(linkTag);
                    
                    // Preload للموارد الهامة
                    if (link.dataset.preload) {
                        const preloadTag = document.createElement('link');
                        preloadTag.rel = 'preload';
                        preloadTag.href = href;
                        preloadTag.as = 'document';
                        document.head.appendChild(preloadTag);
                    }
                });
            }, { rootMargin: '200px' });
            
            document.querySelectorAll('a[href]').forEach(link => {
                if (link.href && link.href.startsWith(window.location.origin)) {
                    linkObserver.observe(link);
                }
            });
        } else {
            // Fallback: Prefetch عند التمرير
            const prefetchOnHover = debounce((e) => {
                const link = e.target.closest('a[href]');
                if (!link) return;
                const href = link.href;
                if (!href || !href.startsWith(window.location.origin)) return;
                if (prefetched.has(href)) return;
                
                prefetched.add(href);
                const linkTag = document.createElement('link');
                linkTag.rel = 'prefetch';
                linkTag.href = href;
                document.head.appendChild(linkTag);
            }, 150);
            
            document.addEventListener('mouseover', prefetchOnHover);
            document.addEventListener('touchstart', prefetchOnHover);
        }
    }

    // ============================================================
    // [6] Performance Badge - عرض أداء الصفحة
    // ============================================================
    function initPerformanceBadge() {
        const badge = document.getElementById('dp-perf-badge');
        if (!badge || !window.performance) return;
        
        // استخدام Performance Observer لقياس دقيق
        if ('PerformanceObserver' in window) {
            const observer = new PerformanceObserver((list) => {
                const entries = list.getEntries();
                let loadTime = 0;
                let domTime = 0;
                
                entries.forEach(entry => {
                    if (entry.entryType === 'navigation') {
                        loadTime = Math.round(entry.loadEventEnd - entry.startTime);
                        domTime = Math.round(entry.domContentLoadedEventEnd - entry.startTime);
                    }
                });
                
                if (loadTime > 0) {
                    badge.textContent = `⚡ ${loadTime}ms | DOM: ${domTime}ms`;
                    badge.style.display = 'inline';
                }
            });
            
            observer.observe({ entryTypes: ['navigation'] });
            
            // إضافة معلومات إضافية عند النقر
            badge.addEventListener('click', () => {
                const entries = performance.getEntriesByType('navigation');
                if (entries.length > 0) {
                    const nav = entries[0];
                    const details = {
                        'DNS': Math.round(nav.domainLookupEnd - nav.domainLookupStart),
                        'TCP': Math.round(nav.connectEnd - nav.connectStart),
                        'SSL': Math.round(nav.secureConnectionStart > 0 ? nav.connectEnd - nav.secureConnectionStart : 0),
                        'Request': Math.round(nav.responseStart - nav.requestStart),
                        'Response': Math.round(nav.responseEnd - nav.responseStart),
                        'DOM': Math.round(nav.domContentLoadedEventEnd - nav.domContentLoadedEventStart),
                        'Load': Math.round(nav.loadEventEnd - nav.loadEventStart)
                    };
                    
                    const msg = Object.entries(details)
                        .map(([k, v]) => `${k}: ${v}ms`)
                        .join(' | ');
                    
                    if (window.showToast) {
                        window.showToast('📊 Performance: ' + msg, 'info', 5000);
                    } else {
                        console.log('📊 Performance:', details);
                    }
                }
            });
        } else {
            // Fallback: استخدام Navigation Timing API
            window.addEventListener('load', () => {
                const timing = window.performance.timing;
                const load = timing.loadEventEnd - timing.navigationStart;
                const dom = timing.domContentLoadedEventEnd - timing.navigationStart;
                badge.textContent = `⚡ ${load}ms | DOM: ${dom}ms`;
            });
        }
    }

    // ============================================================
    // [7] Smart Form Submit - منع الإرسال المزدوج
    // ============================================================
    function initFormProtection() {
        document.addEventListener('submit', function(e) {
            const form = e.target;
            const btn = form.querySelector('[type="submit"]');
            if (!btn) return;
            
            // التحقق من صحة النموذج
            if (!form.checkValidity()) {
                return;
            }
            
            // منع الإرسال المزدوج
            if (btn.dataset.submitting === '1') {
                e.preventDefault();
                return;
            }
            
            // تعطيل الزر
            btn.dataset.submitting = '1';
            const originalText = btn.innerHTML;
            
            // تغيير نص الزر (مع دعم النصوص العربية)
            const loadingText = btn.dataset.loadingText || 
                (document.documentElement.lang === 'ar' ? 'جاري...' : 'Processing...');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + loadingText;
            btn.disabled = true;
            
            // إعادة التفعيل بعد 8 ثواني (حماية من التعليق)
            const restoreBtn = () => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                delete btn.dataset.submitting;
            };
            
            // إعادة التفعيل عند اكتمال الإرسال
            form.addEventListener('submit:complete', restoreBtn, { once: true });
            
            // إعادة التفعيل تلقائياً بعد 8 ثواني
            setTimeout(restoreBtn, 8000);
        }, true);
    }

    // ============================================================
    // [8] Session Heartbeat - الحفاظ على الجلسة
    // ============================================================
    function initHeartbeat() {
        const HEARTBEAT_INTERVAL = 300000; // 5 دقائق
        const HEARTBEAT_URL = location.pathname + '?_ping=1';
        
        let heartbeatId = setInterval(() => {
            // استخدام sendBeacon للحفاظ على الأداء
            if (navigator.sendBeacon) {
                navigator.sendBeacon(HEARTBEAT_URL);
            } else {
                fetch(HEARTBEAT_URL, {
                    method: 'HEAD',
                    credentials: 'same-origin',
                    cache: 'no-cache'
                }).catch(() => {});
            }
        }, HEARTBEAT_INTERVAL);
        
        // إيقاف الـ heartbeat عند مغادرة الصفحة
        window.addEventListener('beforeunload', () => {
            clearInterval(heartbeatId);
        });
        
        // استئناف الـ heartbeat عند العودة للصفحة
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                // تحديث الجلسة عند العودة
                fetch(HEARTBEAT_URL, {
                    method: 'HEAD',
                    credentials: 'same-origin',
                    cache: 'no-cache'
                }).catch(() => {});
            }
        });
    }

    // ============================================================
    // [9] Lazy Loading for IFrames - تحميل الإطارات البطيء
    // ============================================================
    function initLazyIFrames() {
        if (!('IntersectionObserver' in window)) return;
        
        const iframeObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const iframe = entry.target;
                
                if (iframe.dataset.src) {
                    iframe.src = iframe.dataset.src;
                    iframe.classList.add('loaded');
                    delete iframe.dataset.src;
                }
                
                // تحميل محتوى iframe عند الحاجة
                if (iframe.dataset.srcdoc) {
                    iframe.srcdoc = iframe.dataset.srcdoc;
                    delete iframe.dataset.srcdoc;
                }
                
                iframeObserver.unobserve(iframe);
            });
        }, { rootMargin: '300px' });
        
        document.querySelectorAll('iframe[data-src], iframe[data-srcdoc]').forEach(iframe => {
            iframeObserver.observe(iframe);
        });
    }

    // ============================================================
    // [10] Virtual Scrolling - تمرير افتراضي للقوائم الطويلة
    // ============================================================
    /**
     * تنفيذ التمرير الافتراضي لقوائم طويلة
     * @param {HTMLElement} container - حاوية القائمة
     * @param {Array} items - عناصر القائمة
     * @param {Function} renderItem - دالة عرض العنصر
     * @param {Object} options - خيارات إضافية
     */
    function virtualScroll(container, items, renderItem, options = {}) {
        const {
            itemHeight = 50,
            buffer = 5,
            containerHeight = container.clientHeight || 400
        } = options;
        
        let startIndex = 0;
        let endIndex = Math.ceil(containerHeight / itemHeight) + buffer;
        let scrollTop = 0;
        
        // إنشاء حاوية للعناصر المرئية فقط
        const viewport = document.createElement('div');
        viewport.style.cssText = `
            position: relative;
            height: ${items.length * itemHeight}px;
            overflow: hidden;
        `;
        container.innerHTML = '';
        container.appendChild(viewport);
        
        const visibleItems = document.createElement('div');
        visibleItems.style.cssText = `
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
        `;
        viewport.appendChild(visibleItems);
        
        function renderVisible() {
            const start = Math.max(0, startIndex - buffer);
            const end = Math.min(items.length, endIndex + buffer);
            
            let html = '';
            for (let i = start; i < end; i++) {
                const item = items[i];
                const top = i * itemHeight;
                html += renderItem(item, i, top);
            }
            
            visibleItems.innerHTML = html;
            visibleItems.style.transform = `translateY(${start * itemHeight}px)`;
        }
        
        // معالج التمرير مع Throttle
        const onScroll = throttle(() => {
            const newScrollTop = container.scrollTop;
            if (Math.abs(newScrollTop - scrollTop) < 10) return;
            scrollTop = newScrollTop;
            
            const newStart = Math.floor(scrollTop / itemHeight);
            const newEnd = Math.ceil((scrollTop + containerHeight) / itemHeight) + buffer;
            
            if (newStart !== startIndex || newEnd !== endIndex) {
                startIndex = newStart;
                endIndex = newEnd;
                renderVisible();
            }
        }, 16);
        
        container.addEventListener('scroll', onScroll);
        container.style.cssText += `
            overflow-y: auto;
            height: ${containerHeight}px;
        `;
        
        renderVisible();
        
        return {
            destroy: () => {
                container.removeEventListener('scroll', onScroll);
                container.innerHTML = '';
            },
            update: (newItems) => {
                items = newItems;
                viewport.style.height = items.length * itemHeight + 'px';
                renderVisible();
            }
        };
    }

    // ============================================================
    // [11] Memory Management - إدارة الذاكرة
    // ============================================================
    function initMemoryManagement() {
        // مراقبة استخدام الذاكرة (Chrome Only)
        if (window.performance && performance.memory) {
            setInterval(() => {
                const memory = performance.memory;
                const used = memory.usedJSHeapSize / (1024 * 1024);
                const total = memory.totalJSHeapSize / (1024 * 1024);
                const limit = memory.jsHeapSizeLimit / (1024 * 1024);
                
                // تحذير عند استخدام أكثر من 80% من الذاكرة
                if (used / limit > 0.8) {
                    console.warn('⚠️ Memory usage high:', Math.round(used), 'MB /', Math.round(limit), 'MB');
                    
                    // محاولة تحرير الذاكرة
                    if (window.gc) {
                        window.gc();
                    }
                }
            }, 30000);
        }
        
        // إزالة المستمعين عند مغادرة الصفحة
        window.addEventListener('beforeunload', () => {
            // إزالة الـ IntersectionObservers
            if (window._observers) {
                window._observers.forEach(observer => observer.disconnect());
                window._observers = [];
            }
        });
    }

    // ============================================================
    // [12] Network Detection - كشف سرعة الشبكة
    // ============================================================
    function detectNetworkSpeed() {
        if ('connection' in navigator) {
            const connection = navigator.connection;
            
            // كشف نوع الشبكة
            const type = connection.effectiveType || 'unknown';
            const rtt = connection.rtt || 0;
            const downlink = connection.downlink || 0;
            
            // إضافة class للـ body حسب نوع الشبكة
            document.body.classList.add('network-' + type);
            
            // إظهار تحذير للشبكات البطيئة
            if (type === 'slow-2g' || type === '2g') {
                const warning = document.createElement('div');
                warning.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    background: #d9534f;
                    color: #fff;
                    text-align: center;
                    padding: 4px;
                    font-size: 0.75rem;
                    z-index: 99999;
                `;
                warning.textContent = '⚠️ اتصال بطيء - قد يستغرق تحميل الصفحة وقتاً أطول';
                document.body.prepend(warning);
            }
            
            // الاستماع لتغيرات الشبكة
            connection.addEventListener('change', () => {
                const newType = connection.effectiveType;
                document.body.className = document.body.className.replace(/network-\w+/g, '');
                document.body.classList.add('network-' + newType);
            });
        }
    }

    // ============================================================
    // [13] تهيئة جميع الأنظمة
    // ============================================================
    function init() {
        // 1. تحميل الصور البطيء (بأولوية عالية)
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initLazyImages);
        } else {
            initLazyImages();
        }
        
        // 2. تحميل الإطارات البطيء
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initLazyIFrames);
        } else {
            initLazyIFrames();
        }
        
        // 3. Prefetch الروابط (بأولوية منخفضة)
        idleCallback(initPrefetch, { timeout: 2000 });
        
        // 4. Performance Badge (بأولوية منخفضة)
        idleCallback(initPerformanceBadge, { timeout: 3000 });
        
        // 5. Form Protection (فوراً)
        initFormProtection();
        
        // 6. Session Heartbeat (بأولوية منخفضة)
        idleCallback(initHeartbeat, { timeout: 5000 });
        
        // 7. Memory Management (بأولوية منخفضة)
        idleCallback(initMemoryManagement, { timeout: 10000 });
        
        // 8. Network Detection (فوراً)
        detectNetworkSpeed();
        
        console.log('🟢 DI PARMA Performance System initialized');
        console.log('📊 Features: LazyImages, LazyIFrames, Prefetch, PerfBadge, FormProtection, Heartbeat, MemoryManagement, NetworkDetection');
    }

    // ============================================================
    // [14] تصدير الدوال العامة
    // ============================================================
    
    // تصدير الدوال للنطاق العام
    window.dpDebounce = debounce;
    window.dpThrottle = throttle;
    window.dpIdle = idleCallback;
    window.dpVirtualScroll = virtualScroll;
    
    // تصدير مع الدوال القديمة للتوافق
    window.dpDebounce = window.dpDebounce || debounce;
    window.dpThrottle = window.dpThrottle || throttle;
    window.dpIdle = window.dpIdle || idleCallback;
    
    // بدء التهيئة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    console.log('🟢 DI PARMA Performance System loaded');
    console.log('📦 Available: debounce, throttle, idleCallback, virtualScroll');

})();