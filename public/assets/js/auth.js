document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.password-wrapper').forEach(function(wrapper) {
        syncPasswordToggle(wrapper);

        var button = wrapper.querySelector('.password-toggle');
        var input = wrapper.querySelector('input');
        if (!button || !input) {
            return;
        }

        button.addEventListener('click', function() {
            togglePassword(wrapper);
        });

        input.addEventListener('input', function() {
            syncPasswordToggle(wrapper);
        });
    });
});

function togglePassword(wrapper) {
    var input = wrapper.querySelector('input');
    var eyeOn = wrapper.querySelector('.eye-icon');
    var eyeOff = wrapper.querySelector('.eye-off-icon');

    if (!input || !eyeOn || !eyeOff) {
        return;
    }

    var showPassword = input.type === 'password';
    input.type = showPassword ? 'text' : 'password';
    eyeOn.hidden = showPassword;
    eyeOff.hidden = !showPassword;
}

function syncPasswordToggle(wrapper) {
    var input = wrapper.querySelector('input');
    var button = wrapper.querySelector('.password-toggle');
    var eyeOn = wrapper.querySelector('.eye-icon');
    var eyeOff = wrapper.querySelector('.eye-off-icon');

    if (!input || !button || !eyeOn || !eyeOff) {
        return;
    }

    var hasValue = input.value.length > 0;
    button.hidden = !hasValue;

    if (!hasValue) {
        input.type = 'password';
        eyeOn.hidden = false;
        eyeOff.hidden = true;
    }
}
