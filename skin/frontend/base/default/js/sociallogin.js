/**
 * MageAustralia Social Login — vanilla JS for default Maho theme.
 * Uses Google GIS, Apple SIWA JS, and Facebook SDK popup flows.
 * Falls back gracefully if GIS can't render (e.g. origin not authorized).
 */
var MahoSocialLogin = (function() {
    var container, loginUrl;
    var pendingProvider = null, pendingToken = null;

    // init() must be idempotent. Themes that run Turbo re-dispatch DOMContentLoaded
    // after a page swap so legacy scripts re-bind, which means init() is called
    // again against the same document. Without these guards each dispatch injected
    // another Google Identity script and called google.accounts.id.initialize()
    // afresh; the second initialize opened the account-picker iframe, which takes
    // focus. On mobile that stole focus from the search field and the keyboard
    // never opened.
    //
    // The container node is the identity: a real Turbo swap replaces it, so we
    // re-bind against the new node, while a bare re-dispatch is a no-op. The SDKs
    // are global and only ever loaded once per document.
    var boundContainer = null;
    var googleSdkRequested = false;
    var appleSdkRequested = false;
    var facebookSdkRequested = false;

    function init() {
        var el = document.getElementById('social-login-buttons');
        if (!el || el === boundContainer) return;

        container = el;
        boundContainer = el;
        loginUrl = container.dataset.loginUrl;

        // Auth SDKs (Google GSI ~98KB, Apple, Facebook) are deferred off the critical
        // path — see scheduleSocialSdks(). First load is on first interaction or when idle.
        // On a Turbo re-init the SDKs already exist, so (re-)render into the new container
        // immediately instead of deferring (else the Google button would be missing).
        if (socialSdksLoaded) loadSocialSdksNow();
        else scheduleSocialSdks();

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

    // ---- Deferred SDK loading ----
    // Google (GSI ~98KB), Apple and Facebook auth SDKs are needed only for the social
    // buttons + One Tap prompt — none of it is render-critical. Loading them on
    // DOMContentLoaded put ~100KB of third-party JS on the critical path of every page.
    // Instead load on the first user interaction (they may be about to sign in) OR when
    // the browser goes idle (so One Tap still auto-prompts) — whichever comes first. The
    // per-SDK requested-flags keep this idempotent under Turbo re-inits.
    var socialSdksLoaded = false;
    function loadSocialSdksNow() {
        if (!container) return;
        if (container.dataset.googleClientId) loadGoogleSdk();
        if (container.dataset.appleServiceId) loadAppleSdk();
        if (container.dataset.facebookAppId) loadFacebookSdk();
    }
    function loadSocialSdks() {
        if (socialSdksLoaded) return;
        socialSdksLoaded = true;
        loadSocialSdksNow();
    }
    function scheduleSocialSdks() {
        var fire = function() { loadSocialSdks(); };
        ['pointerdown', 'keydown', 'touchstart'].forEach(function(ev) {
            window.addEventListener(ev, fire, { once: true, passive: true });
        });
        if (window.requestIdleCallback) {
            requestIdleCallback(fire, { timeout: 2000 });
        } else {
            setTimeout(fire, 1200);
        }
    }

    function loadGoogleSdk() {
        // Already loaded: the container is new (Turbo swap) but google.accounts.id
        // is initialised. Re-render the button into the new node and stop. Calling
        // initialize() or prompt() again would re-open the One Tap account picker.
        if (window.google && google.accounts && google.accounts.id) {
            renderGoogleButton();
            return;
        }
        // Requested but still downloading: the in-flight script's onload will render.
        if (googleSdkRequested) return;
        googleSdkRequested = true;

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

            // Render the native GSI button. renderGoogleButton() self-observes if
            // the container is hidden (0 width) and re-renders once it is shown.
            renderGoogleButton();

            // One Tap auto-prompt — gated: shows on every page until the user
            // dismisses it, then stays hidden until they reach cart/checkout.
            maybePromptOneTap();
        };
        document.head.appendChild(script);
    }

    // Minimal config (no `text`) so GSI shows the personalised "Sign in as <name>"
    // button when the visitor has a Google session. Clears first so re-renders
    // (after the container becomes visible) don't stack two buttons.
    /** Phone-sized viewport. Matches the theme's own mobile breakpoint. */
    function isSmallViewport() {
        if (window.matchMedia) return window.matchMedia('(max-width: 767px)').matches;
        return (window.innerWidth || document.documentElement.clientWidth || 0) <= 767;
    }

    function renderGoogleButton() {
        var target = document.getElementById('social-login-google-button');
        if (!target || !window.google || !google.accounts || !google.accounts.id) return;
        var width = Math.max(200, Math.min(target.offsetWidth || 360, 400));
        target.innerHTML = '';
        google.accounts.id.renderButton(target, { theme: 'outline', size: 'large', width: width });

        // If rendered while hidden (offsetWidth 0 -> 360px fallback), the button
        // can overflow a narrow container. Re-render once the container first
        // becomes visible so the width is measured against the real (padded)
        // dropdown. Both callers — first SDK load AND the "already loaded"
        // re-render after a hole-punch/Turbo swap — go through here, so the
        // re-init path (dropdown still hidden) now also self-corrects. Guard so
        // each node is observed only once.
        if (target.offsetWidth === 0 && 'ResizeObserver' in window && !target._gsiObserved) {
            target._gsiObserved = true;
            var ro = new ResizeObserver(function(entries) {
                for (var i = 0; i < entries.length; i++) {
                    if (entries[i].contentRect.width > 0) {
                        target._gsiObserved = false;
                        ro.disconnect();
                        renderGoogleButton();
                        return;
                    }
                }
            });
            ro.observe(target);
        }
    }

    function maybePromptOneTap() {
        if (!window.google || !google.accounts || !google.accounts.id) return;

        // Never auto-prompt on a phone. One Tap renders as a bottom sheet whose
        // account-picker iframe takes focus as soon as it appears. On a narrow
        // viewport that competes with whatever the visitor just tapped: opening the
        // header search drawer focuses the search field, One Tap lands a beat later,
        // steals the focus, and the on-screen keyboard never opens.
        //
        // The rendered Google button is unaffected, and an explicit tap on it still
        // calls prompt() through loginGoogle(). This only removes the uninvited
        // prompt, which Google itself discourages on mobile.
        if (isSmallViewport()) return;

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
        if (appleSdkRequested || window.AppleID) return;
        appleSdkRequested = true;

        var script = document.createElement('script');
        script.src = 'https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js';
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
    }

    function loadFacebookSdk() {
        if (facebookSdkRequested || window.FB || document.getElementById('facebook-jssdk')) return;
        facebookSdkRequested = true;

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
        // Return the customer to the page they were on after sign-in / linking,
        // instead of the account dashboard -- except on the auth pages themselves.
        var path = window.location.pathname || '';
        if (path && !/\/customer\/account\/(login|create|logout|forgotpassword)/i.test(path)) {
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

    return { login: login, linkAccount: linkAccount, init: init };
})();
