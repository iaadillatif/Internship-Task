/*
 * User Registration Form Handler
 * 
 * Manages account creation via AJAX to backend API:
 * - Client-side validation before submission
 * - JSON POST to registration endpoint
 * - Error handling with user feedback
 * - Redirect to login after successful registration
 */

$(document).ready(function () {
    // Password toggle for password field
    $('#passwordToggle').on('click', function (e) {
        e.preventDefault();
        const passwordInput = $('#password');
        const toggleIcon = $(this).find('i');

        if (passwordInput.attr('type') === 'password') {
            passwordInput.attr('type', 'text');
            toggleIcon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            passwordInput.attr('type', 'password');
            toggleIcon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    // Password toggle for confirm password field
    $('#confirmPasswordToggle').on('click', function (e) {
        e.preventDefault();
        const confirmPasswordInput = $('#confirmPassword');
        const toggleIcon = $(this).find('i');

        if (confirmPasswordInput.attr('type') === 'password') {
            confirmPasswordInput.attr('type', 'text');
            toggleIcon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            confirmPasswordInput.attr('type', 'password');
            toggleIcon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    $('#registerBtn').on('click', function () {
        clearErrors();
        const email = $('#email').val().trim();
        const password = $('#password').val();
        const confirmPassword = $('#confirmPassword').val();
        const fullName = $('#fullName').val().trim();

        // Validation
        if (!fullName) {
            showFieldError('fullNameError', 'Full name is required');
            return;
        }

        if (fullName.length < 2) {
            showFieldError('fullNameError', 'Full name must be at least 2 characters');
            return;
        }

        if (!email) {
            showFieldError('emailError', 'Email is required');
            return;
        }

        if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
            showFieldError('emailError', 'Please enter a valid email');
            return;
        }

        if (!password) {
            showFieldError('passwordError', 'Password is required');
            return;
        }

        if (password.length < 6) {
            showFieldError('passwordError', 'Password must be at least 6 characters');
            return;
        }

        if (password !== confirmPassword) {
            showFieldError('confirmError', 'Passwords do not match');
            return;
        }

        // Send to backend
        $.ajax({
            url: 'php/register.php',
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({
                email: email,
                password: password,
                fullName: fullName
            }),
            success: function (response) {
                if (response.success) {
                    showSuccess('Account created successfully! Redirecting to login...');
                    setTimeout(function () {
                        window.location.href = 'login.html';
                    }, 2000);
                } else {
                    showError(response.message);
                }
            },
            statusCode: {
                400: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Invalid input. Please check your data.');
                    } catch (e) {
                        showError('Invalid input. Please check your data.');
                    }
                },
                409: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Email is already registered');
                    } catch (e) {
                        showError('Email is already registered');
                    }
                },
                500: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Server error. Please try again later.');
                    } catch (e) {
                        showError('Server error. Please try again later.');
                    }
                }
            },
            error: function (xhr, status, error) {
                if (xhr.status !== 400 && xhr.status !== 409 && xhr.status !== 500) {
                    showError('Network error. Please check your connection.');
                }
            }
        });
    });

    function clearErrors() {
        $('#fullNameError').html('');
        $('#emailError').html('');
        $('#passwordError').html('');
        $('#confirmError').html('');
        $('#errorMessage').addClass('d-none').html('');
        $('#successMessage').addClass('d-none').html('');
    }

    function showFieldError(fieldId, message) {
        $('#' + fieldId).html('<i class="fas fa-times-circle"></i> ' + message);
    }

    function showError(message) {
        $('#errorText').html(message);
        $('#errorMessage').removeClass('d-none');
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function showSuccess(message) {
        $('#successText').html(message);
        $('#successMessage').removeClass('d-none');
        $('html, body').animate({ scrollTop: 0 }, 300);
    }
});
