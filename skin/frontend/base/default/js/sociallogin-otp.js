/**
 * MageAustralia Social Login - OTP (email code) login for the default Maho theme.
 *
 * Two-step flow:
 *   1. POST {form_key, email, purpose, channel} to the request endpoint, which
 *      always returns a uniform message (no account enumeration). Reveal the
 *      code step and start a client-side cooldown matching the server guard.
 *   2. POST {form_key, email, code} to the verify endpoint; on success redirect.
 *
 * Self-contained vanilla JS - no Prototype, no jQuery, no MahoSocialLogin dep.
 */
(function() {
    var container, requestUrl, verifyUrl, formKey, cooldown;
    var sendBtn, resendBtn, verifyBtn, emailInput, codeInput;
    var emailStep, codeStep, messageEl;
    var inFlight = false;
    var cooldownTimer = null;

    function init() {
        container = document.getElementById('sociallogin-otp');
        if (!container) return;

        requestUrl = container.dataset.requestUrl;
        verifyUrl = container.dataset.verifyUrl;
        formKey = container.dataset.formKey;
        cooldown = parseInt(container.dataset.cooldown, 10) || 0;

        emailStep = container.querySelector('[data-otp-step="email"]');
        codeStep = container.querySelector('[data-otp-step="code"]');
        messageEl = container.querySelector('.sociallogin-otp-message');

        emailInput = container.querySelector('#sociallogin-otp-email');
        codeInput = container.querySelector('#sociallogin-otp-code');
        sendBtn = container.querySelector('[data-otp-send]');
        resendBtn = container.querySelector('[data-otp-resend]');
        verifyBtn = container.querySelector('[data-otp-verify]');

        if (sendBtn) sendBtn.addEventListener('click', function() { requestCode(sendBtn); });
        if (resendBtn) resendBtn.addEventListener('click', function() { requestCode(resendBtn); });
        if (verifyBtn) verifyBtn.addEventListener('click', verifyCode);

        // Pressing Enter inside our inputs must not submit the surrounding page
        // login form; route it to the relevant OTP action instead.
        if (emailInput) {
            emailInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    e.preventDefault();
                    requestCode(sendBtn);
                }
            });
        }
        if (codeInput) {
            codeInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.keyCode === 13) {
                    e.preventDefault();
                    verifyCode();
                }
            });
        }
    }

    function requestCode(btn) {
        if (inFlight) return;
        var email = emailInput ? emailInput.value.trim() : '';
        if (!email) {
            showMessage('Please enter your email address.');
            if (emailInput) emailInput.focus();
            return;
        }
        hideMessage();
        setInFlight(true);

        post(requestUrl, { form_key: formKey, email: email, purpose: 'login', channel: 'email' })
            .then(function(data) {
                setInFlight(false);
                if (data && data.message) {
                    showMessage(data.message);
                }
                // Reveal the code step regardless (uniform response = no enumeration).
                if (emailStep) emailStep.style.display = 'none';
                if (codeStep) codeStep.style.display = '';
                if (codeInput) codeInput.focus();
                startCooldown();
            })
            .catch(function() {
                setInFlight(false);
                showMessage('Something went wrong. Please try again.');
            });
    }

    function verifyCode() {
        if (inFlight) return;
        var email = emailInput ? emailInput.value.trim() : '';
        var code = codeInput ? codeInput.value.trim() : '';
        if (!code) {
            showMessage('Please enter the code we sent you.');
            if (codeInput) codeInput.focus();
            return;
        }
        hideMessage();
        setInFlight(true);

        post(verifyUrl, { form_key: formKey, email: email, code: code })
            .then(function(data) {
                if (data && data.ok && data.redirect) {
                    window.location.href = data.redirect;
                    return;
                }
                setInFlight(false);
                showMessage((data && data.message) || 'That code was not valid. Please try again.');
            })
            .catch(function() {
                setInFlight(false);
                showMessage('Something went wrong. Please try again.');
            });
    }

    function post(url, fields) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams(fields).toString(),
            credentials: 'same-origin'
        }).then(function(response) {
            return response.json();
        });
    }

    // ---- Cooldown: disable send/resend for `cooldown` seconds ----

    function startCooldown() {
        if (cooldown <= 0) return;
        var remaining = cooldown;
        var sendLabel = labelEl(sendBtn);
        var sendText = sendLabel ? sendLabel.textContent : '';
        var resendText = resendBtn ? resendBtn.textContent : '';

        clearInterval(cooldownTimer);

        function tick() {
            if (sendBtn) sendBtn.disabled = true;
            if (resendBtn) resendBtn.disabled = true;
            if (sendLabel) sendLabel.textContent = sendText + ' (' + remaining + ')';
            if (resendBtn) resendBtn.textContent = resendText + ' (' + remaining + ')';
            remaining--;
            if (remaining < 0) {
                clearInterval(cooldownTimer);
                cooldownTimer = null;
                if (sendBtn && !inFlight) sendBtn.disabled = false;
                if (resendBtn && !inFlight) resendBtn.disabled = false;
                if (sendLabel) sendLabel.textContent = sendText;
                if (resendBtn) resendBtn.textContent = resendText;
            }
        }

        tick();
        cooldownTimer = setInterval(tick, 1000);
    }

    // ---- UI helpers ----

    function labelEl(btn) {
        if (!btn) return null;
        return btn.querySelector('span') || btn;
    }

    function setInFlight(state) {
        inFlight = state;
        if (verifyBtn) verifyBtn.disabled = state;
        // Don't re-enable send/resend mid-cooldown.
        if (state || !cooldownTimer) {
            if (sendBtn) sendBtn.disabled = state;
            if (resendBtn) resendBtn.disabled = state;
        }
    }

    function showMessage(msg) {
        if (!messageEl) return;
        messageEl.textContent = msg;
        messageEl.style.display = '';
    }

    function hideMessage() {
        if (messageEl) messageEl.style.display = 'none';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
