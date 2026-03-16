/**
 * MageAustralia Social Login — vanilla JS for default Maho theme.
 * Uses Google GIS, Apple SIWA JS, and Facebook SDK popup flows.
 * Falls back gracefully if GIS can't render (e.g. origin not authorized).
 */
var MahoSocialLogin = (function() {
    var container, apiUrl;
    var pendingProvider = null, pendingToken = null;

    function init() {
        container = document.getElementById('social-login-buttons');
        if (!container) return;
        apiUrl = container.dataset.apiUrl;

        if (container.dataset.googleClientId) loadGoogleSdk();
        if (container.dataset.appleServiceId) loadAppleSdk();
        if (container.dataset.facebookAppId) loadFacebookSdk();
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
        };
        document.head.appendChild(script);
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
        var body = { provider: provider, token: token };
        if (password) body.password = password;

        setButtonsDisabled(true);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl);
        xhr.setRequestHeader('Content-Type', 'application/ld+json');
        xhr.setRequestHeader('Accept', 'application/ld+json');
        xhr.onload = function() {
            setButtonsDisabled(false);
            var data;
            try { data = JSON.parse(xhr.responseText); } catch(e) {
                showError('An unexpected error occurred.');
                return;
            }

            if (xhr.status !== 200 && xhr.status !== 201) {
                var msg = data['hydra:description'] || data.detail || data.message || 'Authentication failed.';
                showError(msg);
                return;
            }

            if (data.linkRequired === 'account_exists') {
                pendingProvider = provider;
                pendingToken = token;
                showLinkPrompt(data.customer ? data.customer.email : 'your email');
                return;
            }

            // Create a Maho session by posting the JWT to our controller
            createSession(data.authToken);
        };
        xhr.onerror = function() {
            setButtonsDisabled(false);
            showError('Network error. Please try again.');
        };
        xhr.send(JSON.stringify(body));
    }

    function createSession(jwt) {
        // Submit the JWT to a Maho controller that creates a session cookie
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = '/sociallogin/auth/callback';
        form.style.display = 'none';

        var tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = 'token';
        tokenInput.value = jwt;
        form.appendChild(tokenInput);

        // Include form key for CSRF protection
        var formKeyEl = document.querySelector('input[name="form_key"]');
        if (formKeyEl) {
            var fk = document.createElement('input');
            fk.type = 'hidden';
            fk.name = 'form_key';
            fk.value = formKeyEl.value;
            form.appendChild(fk);
        }

        document.body.appendChild(form);
        form.submit();
    }

    function linkAccount(event) {
        event.preventDefault();
        var password = document.getElementById('social-link-password');
        if (!password || !password.value || !pendingProvider || !pendingToken) return;
        hideError();
        authenticate(pendingProvider, pendingToken, password.value);
    }

    // ---- UI Helpers ----

    function showLinkPrompt(email) {
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
