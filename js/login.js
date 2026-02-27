/*
 * User Login Form Handler
 * 
 * Handles authentication and session token management:
 * - AJAX login request to backend API
 * - Session token stored in browser localStorage
 * - Token persists across browser sessions
 * - Error handling for authentication failures
 */

$(document).ready(function () {
    // Password toggle functionality
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

    $('#loginBtn').on('click', function () {
        clearErrors();
        const email = $('#email').val().trim();
        const password = $('#password').val();

        if (!email || !password) {
            showError('Email and password are required');
            return;
        }

        $.ajax({
            url: 'php/login.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                email: email,
                password: password
            }),
            success: function (response) {
                if (response.success) {
                    localStorage.setItem('sessionToken', response.token);
                    window.location.href = 'profile.html';
                } else {
                    showError(response.message);
                }
            },
            statusCode: {
                400: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Invalid email or password');
                    } catch (e) {
                        showError('Invalid email or password');
                    }
                },
                401: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Invalid email or password');
                    } catch (e) {
                        showError('Invalid email or password');
                    }
                },
                405: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Method not allowed');
                    } catch (e) {
                        showError('Invalid request method');
                    }
                },
                500: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Server error');
                    } catch (e) {
                        showError('Server error. Please try again later.');
                    }
                }
            },
            error: function (xhr) {
                if (xhr.status !== 400 && xhr.status !== 401 && xhr.status !== 405 && xhr.status !== 500) {
                    showError('Network error. Please check your connection.');
                }
            }
        });
    });

    function clearErrors() {
        $('#emailError').text('');
        $('#passwordError').text('');
        $('#errorMessage').addClass('d-none').text('');
    }

    function showError(message) {
        $('#errorMessage').text(message).removeClass('d-none');
    }
});
