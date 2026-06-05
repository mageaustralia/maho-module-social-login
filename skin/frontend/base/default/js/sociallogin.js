/**
 * MageAustralia Social Login — vanilla JS for default Maho theme.
 * Uses Google GIS, Apple SIWA JS, and Facebook SDK popup flows.
 * Falls back gracefully if GIS can't render (e.g. origin not authorized).
 */
var MahoSocialLogin = (function() {
    var container, loginUrl;
    var pendingProvider = null, pendingToken = null;

    function init() {
        container = document.getElementById('social-login-buttons');
        if (!container) return;
        loginUrl = container.dataset.loginUrl;

        if (container.dataset.googleClientId) loadGoogleSdk();
        if (container.dataset.appleServiceId) loadAppleSdk();
        if (container.dataset.facebookAppId) loadFacebookSdk();

        initForgotPassword();

        // The link-prompt lives inside the surrounding login <form>, so pressing
        // Enter in the password would submit that form (and trip its validation).
        // Route Enter to the link action instead.
        var linkPwd = container.querySelector('#social-link-password');
        if (linkPwd) {
            linkPwd.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    e.preventDefault();
                    linkAccount(e);
                }
            });
        }

        guardDropdownDuringGsi();

        initOtpLink();
    }

    // ---- OTP-based account linking (alternative to the password prompt) ----
    // Additive: reuses pendingProvider/pendingToken set when the link prompt is shown.

    function initOtpLink() {
        var startBtn = container.querySelector('[data-otp-link-start]');
        if (startBtn) {
            startBtn.addEventListener('click', function(e) {
                e.preventDefault();
                otpLinkRequest();
            });
        }
        var verifyBtn = container.querySelector('[data-otp-link-verify]');
        if (verifyBtn) {
            verifyBtn.addEventListener('click', function(e) {
                e.preventDefault();
                otpLinkVerify();
            });
        }
    }

    function otpFormKey() {
        var fk = document.querySelector('input[name="form_key"]');
        return fk ? fk.value : '';
    }

    function otpLinkRedirect(body) {
        var path = window.location.pathname || '';
        if (/\/(firecheckout|checkout|onestepcheckout)(\/|$)/.test(path)) {
            body.set('redirect', path + (window.location.search || ''));
        }
    }

    function otpLinkRequest() {
        if (!pendingProvider || !pendingToken) return;
        var url = container.dataset.otpLinkRequestUrl;
        if (!url) return;
        hideError();

        var body = new URLSearchParams();
        body.set('provider', pendingProvider);
        body.set('token', pendingToken);
        body.set('form_key', otpFormKey());

        var xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function() {
            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = {}; }
            var sub = container.querySelector('.social-login-otp-link-code');
            if (sub) sub.style.display = '';
            var startWrap = container.querySelector('.social-login-otp-link-start');
            if (startWrap) startWrap.style.display = 'none';
            showInfo(data.message || 'If your details match an account, a verification code has been sent.');
            var codeInput = document.getElementById('social-link-otp-code');
            if (codeInput) codeInput.focus();
        };
        xhr.onerror = function() { showError('Network error. Please try again.'); };
        xhr.send(body.toString());
    }

    function otpLinkVerify() {
        if (!pendingProvider || !pendingToken) return;
        var url = container.dataset.otpLinkVerifyUrl;
        if (!url) return;
        var codeInput = document.getElementById('social-link-otp-code');
        var code = codeInput ? codeInput.value.trim() : '';
        if (!code) {
            showError('Please enter the verification code.');
            if (codeInput) codeInput.focus();
            return;
        }
        hideError();

        var body = new URLSearchParams();
        body.set('provider', pendingProvider);
        body.set('token', pendingToken);
        body.set('code', code);
        body.set('form_key', otpFormKey());
        otpLinkRedirect(body);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function() {
            var data;
            try { data = JSON.parse(xhr.responseText); } catch (e) {
                showError('An unexpected error occurred.');
                return;
            }
            if (data.ok) {
                window.location.href = data.redirect || '/customer/account';
                return;
            }
            showError(data.message || 'Invalid or expired code.');
        };
        xhr.onerror = function() { showError('Network error. Please try again.'); };
        xhr.send(body.toString());
    }

    function showInfo(msg) {
        var prompt = document.getElementById('social-login-link-prompt');
        if (!prompt) return;
        var msgEl = prompt.querySelector('.social-login-info');
        if (msgEl) msgEl.textContent = msg;
    }

    // When the buttons live inside a closable header dropdown, a Google sign-in
    // opens a popup; clicking back on the page then closes the dropdown, hides
    // the GSI iframe and orphans the sign-in. Keep the dropdown open while the
    // popup is in flight. Theme supplies the ancestor selector via data-attr.
    function guardDropdownDuringGsi() {
        var sel = container.getAttribute('data-keep-open-selector');
        if (!sel) return;
        var dropdown = container.closest(sel);
        if (!dropdown) return; // e.g. the full login page — no dropdown to guard
        var openClass = container.getAttribute('data-keep-open-class') || 'active';
        var guarding = false;

        window.addEventListener('blur', function() {
            if (!dropdown.classList.contains(openClass)) return;
            guarding = true;
            var onFocus = function() {
                setTimeout(function() { guarding = false; }, 1000);
                window.removeEventListener('focus', onFocus);
            };
            window.addEventListener('focus', onFocus);
        });

        new MutationObserver(function() {
            if (guarding && !dropdown.classList.contains(openClass)) {
                dropdown.classList.add(openClass);
            }
        }).observe(dropdown, { attributes: true, attributeFilter: ['class'] });
    }

    // ---- Forgot-password: reuse the theme's native <dialog> (base/default mechanism) ----

    function initForgotPassword() {
        var link = container.querySelector('#social-login-link-prompt a[href*="forgotpassword"]');
        var dialog = document.getElementById('forgot-password-dialog');
        if (!link || !dialog) return; // no theme dialog on this page -> let the link navigate
        link.addEventListener('click', function(e) {
            e.preventDefault();
            dialog.showModal();
        });
    }

    // ---- SDK Loaders ----

    function loadGoogleSdk() {
        var script = document.createElement('script');
        script.src = 'https://accounts.google.com/gsi/client';
        script.async = true;
        script.defer = true;
        script.onload = function() {
            google.accounts.id.initialize({
                client_id: container.dataset.googleClientId,
                callback: function(response) {
                    if (response.credential) {
                        authenticate('google', response.credential);
                    }
                },
                auto_select: false
            });

            // Render the native GSI button. When it lives in a hidden container
            // (e.g. the header account dropdown) GSI bakes in a 0 width, so
            // re-render once the container first becomes visible.
            renderGoogleButton();
            var gTarget = document.getElementById('social-login-google-button');
            if (gTarget && gTarget.offsetWidth === 0 && 'ResizeObserver' in window) {
                var ro = new ResizeObserver(function(entries) {
                    for (var i = 0; i < entries.length; i++) {
                        if (entries[i].contentRect.width > 0) {
                            renderGoogleButton();
                            ro.disconnect();
                            return;
                        }
                    }
                });
                ro.observe(gTarget);
            }

            // One Tap auto-prompt — gated: shows on every page until the user
            // dismisses it, then stays hidden until they reach cart/checkout.
            maybePromptOneTap();
        };
        document.head.appendChild(script);
    }

    // Minimal config (no `text`) so GSI shows the personalised "Sign in as <name>"
    // button when the visitor has a Google session. Clears first so re-renders
    // (after the container becomes visible) don't stack two buttons.
    function renderGoogleButton() {
        var target = document.getElementById('social-login-google-button');
        if (!target || !window.google || !google.accounts || !google.accounts.id) return;
        var width = Math.max(200, Math.min(target.offsetWidth || 360, 400));
        target.innerHTML = '';
        google.accounts.id.renderButton(target, { theme: 'outline', size: 'large', width: width });
    }

    function maybePromptOneTap() {
        if (!window.google || !google.accounts || !google.accounts.id) return;
        var path = window.location.pathname || '';
        var onCheckout = /\/checkout(\/|$)/.test(path) || /\/onestepcheckout/.test(path);
        var dismissed = false;
        try { dismissed = localStorage.getItem('mahoOneTapDismissed') === '1'; } catch (e) {}
        if (onCheckout) {
            // Re-enable the prompt once the visitor is in the buying flow.
            try { localStorage.removeItem('mahoOneTapDismissed'); } catch (e) {}
            dismissed = false;
        }
        if (dismissed) return;
        google.accounts.id.prompt(function(notification) {
            if (notification.isDismissedMoment && notification.isDismissedMoment()) {
                var reason = notification.getDismissedReason ? notification.getDismissedReason() : '';
                if (reason === 'user_cancel' || reason === 'cancel_called' || reason === 'tap_outside' || reason === 'flow_restarted') {
                    try { localStorage.setItem('mahoOneTapDismissed', '1'); } catch (e) {}
                }
            }
        });
    }

    function loadAppleSdk() {
        var script = document.createElement('script');
        script.src = 'https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }

    function loadFacebookSdk() {
        window.fbAsyncInit = function() {
            FB.init({ appId: container.dataset.facebookAppId, cookie: true, xfbml: false, version: 'v19.0' });
        };
        var script = document.createElement('script');
        script.id = 'facebook-jssdk';
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }

    // ---- Login Flows ----

    function login(provider) {
        hideError();
        if (provider === 'google') loginGoogle();
        else if (provider === 'apple') loginApple();
        else if (provider === 'facebook') loginFacebook();
    }

    function loginGoogle() {
        if (!window.google || !google.accounts || !google.accounts.id) {
            showError('Google Sign-In is not available. Please refresh the page.');
            return;
        }

        // Use GIS One Tap prompt — works when origin is authorized
        google.accounts.id.prompt(function(notification) {
            if (notification.isNotDisplayed() || notification.isSkippedMoment()) {
                // GIS prompt blocked (origin not authorized, or popup blocked).
                // Fall back to standard OAuth2 popup with authorization code flow.
                loginGoogleFallback();
            }
        });
    }

    function loginGoogleFallback() {
        var clientId = container.dataset.googleClientId;
        var width = 500, height = 600;
        var left = (screen.width - width) / 2;
        var top = (screen.height - height) / 2;

        // Use 'token' response_type to get id_token directly
        var params = new URLSearchParams({
            client_id: clientId,
            redirect_uri: 'storagerelay://' + window.location.protocol.slice(0,-1) + '/' + window.location.host,
            response_type: 'token id_token',
            scope: 'openid email profile',
            nonce: Math.random().toString(36).substring(2),
            prompt: 'select_account'
        });

        // Google's OAuth endpoint — opens a popup
        var popup = window.open(
            'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString(),
            'google-signin',
            'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',menubar=no,toolbar=no,status=no'
        );

        if (!popup) {
            showError('Popup blocked. Please allow popups for this site.');
            return;
        }

        // Poll for the popup to redirect back with the token
        var pollTimer = setInterval(function() {
            try {
                if (popup.closed) {
                    clearInterval(pollTimer);
                    return;
                }
                // When the popup redirects back, we can read the hash
                if (popup.location.host === window.location.host) {
                    clearInterval(pollTimer);
                    var hash = popup.location.hash;
                    popup.close();
                    if (hash) {
                        var hashParams = new URLSearchParams(hash.substring(1));
                        var idToken = hashParams.get('id_token');
                        if (idToken) {
                            authenticate('google', idToken);
                            return;
                        }
                    }
                    showError('No token received from Google.');
                }
            } catch(e) {
                // Cross-origin error — popup hasn't redirected yet, keep polling
            }
        }, 300);

        // Timeout after 2 minutes
        setTimeout(function() {
            clearInterval(pollTimer);
            if (popup && !popup.closed) popup.close();
        }, 120000);
    }

    function loginApple() {
        var serviceId = container.dataset.appleServiceId;
        if (!serviceId || !window.AppleID || !AppleID.auth) {
            showError('Apple Sign-In is not available. Please refresh the page.');
            return;
        }

        AppleID.auth.init({
            clientId: serviceId,
            scope: 'name email',
            redirectURI: window.location.origin + '/social-auth/callback',
            usePopup: true
        });

        AppleID.auth.signIn()
            .then(function(response) {
                if (response.authorization && response.authorization.id_token) {
                    authenticate('apple', response.authorization.id_token);
                } else {
                    showError('No token returned from Apple.');
                }
            })
            .catch(function(err) {
                if (err.error !== 'popup_closed_by_user') {
                    showError(err.error || 'Apple sign-in failed.');
                }
            });
    }

    function loginFacebook() {
        if (typeof FB === 'undefined') {
            showError('Facebook SDK is not available. Please refresh the page.');
            return;
        }

        FB.login(function(response) {
            if (response.authResponse && response.authResponse.accessToken) {
                authenticate('facebook', response.authResponse.accessToken);
            }
        }, { scope: 'email,public_profile' });
    }

    // ---- API Call ----

    function authenticate(provider, token, password) {
        setButtonsDisabled(true);

        var body = new URLSearchParams();
        body.set('provider', provider);
        body.set('token', token);
        if (password) body.set('password', password);
        var fk = document.querySelector('input[name="form_key"]');
        if (fk) body.set('form_key', fk.value);
        // On checkout, return the customer to checkout after sign-in / linking
        // instead of the account dashboard. Other pages keep the default.
        var path = window.location.pathname || '';
        if (/\/(firecheckout|checkout|onestepcheckout)(\/|$)/.test(path)) {
            body.set('redirect', path + (window.location.search || ''));
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', loginUrl);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function() {
            setButtonsDisabled(false);
            var data;
            try { data = JSON.parse(xhr.responseText); } catch(e) {
                showError('An unexpected error occurred.');
                return;
            }

            if (data.linkRequired === 'account_exists') {
                pendingProvider = provider;
                pendingToken = token;
                showLinkPrompt(data.email || 'your email');
                return;
            }

            if (xhr.status >= 200 && xhr.status < 300 && data.success) {
                window.location.href = data.redirect || '/customer/account';
                return;
            }

            showError(data.error || 'Sign-in failed. Please try again.');
        };
        xhr.onerror = function() {
            setButtonsDisabled(false);
            showError('Network error. Please try again.');
        };
        xhr.send(body.toString());
    }

    function linkAccount(event) {
        if (event) event.preventDefault();
        if (!pendingProvider || !pendingToken) return;
        var password = document.getElementById('social-link-password');
        if (!password || !password.value) {
            showError('Please enter your password to link your account.');
            if (password) password.focus();
            return;
        }
        hideError();
        authenticate(pendingProvider, pendingToken, password.value);
    }

    // ---- UI Helpers ----

    function showLinkPrompt(email) {
        hideError();
        var actions = container.querySelector('.social-login-actions');
        var divider = container.querySelector('.social-login-divider');
        var prompt = document.getElementById('social-login-link-prompt');
        var msgEl = prompt.querySelector('.social-login-info');

        if (actions) actions.style.display = 'none';
        if (divider) divider.style.display = 'none';
        msgEl.textContent = 'An account with ' + email + ' already exists. Enter your password to link it.';
        prompt.style.display = '';
        var pwd = document.getElementById('social-link-password');
        if (pwd) pwd.focus();
    }

    function showError(msg) {
        var el = document.getElementById('social-login-error');
        if (el) { el.textContent = msg; el.style.display = ''; }
    }

    function hideError() {
        var el = document.getElementById('social-login-error');
        if (el) el.style.display = 'none';
    }

    function setButtonsDisabled(disabled) {
        var btns = container.querySelectorAll('.social-login-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].disabled = disabled;
            btns[i].style.opacity = disabled ? '0.6' : '';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return { login: login, linkAccount: linkAccount };
})();
