/*
 * User Profile Management Handler
 * 
 * Manages profile data with token-based authentication:
 * - Load and display profile data on page load
 * - Edit profile information via modal form
 * - Handle session token validation
 * - Logout with session cleanup
 */

$(document).ready(function () {
    const token = localStorage.getItem('sessionToken');

    if (!token) {
        window.location.href = 'login.html';
        return;
    }

    loadProfile();

    // Edit Profile Modal
    $('#editProfileBtn').on('click', function () {
        $('#editProfileModal').addClass('show');
    });

    $('#closeEditModal').on('click', function () {
        $('#editProfileModal').removeClass('show');
    });

    $('#editProfileModal').on('click', function (e) {
        if (e.target === this) {
            $(this).removeClass('show');
        }
    });

    const sectionFormModal = document.getElementById('sectionFormModal');
    const sectionFormModalTitle = document.getElementById('sectionFormModalTitle');
    const sectionFormModalBody = document.getElementById('sectionFormModalBody');
    const closeSectionModalBtn = document.getElementById('closeSectionModal');

    let activeSectionForm = null;
    let activeSectionFormParent = null;
    let activeSectionFormNextSibling = null;

    if (closeSectionModalBtn) {
        closeSectionModalBtn.addEventListener('click', function () {
            closeSectionFormModal(false);
        });
    }

    if (sectionFormModal) {
        sectionFormModal.addEventListener('click', function (e) {
            if (e.target === sectionFormModal) {
                closeSectionFormModal(false);
            }
        });
    }

    // Edit Form Submit
    $('#editProfileForm').on('submit', function (e) {
        e.preventDefault();
        clearMessages();

        const firstName = $('#editFirstName').val().trim();
        const lastName = $('#editLastName').val().trim();
        const phone = $('#editPhone').val().trim();
        const dob = $('#editDOB').val().trim();
        const designation = $('#editDesignation').val().trim();
        const gender = $('#editGender').val().trim();
        const country = $('#editCountry').val().trim();
        const state = $('#editState').val().trim();
        const city = $('#editCity').val().trim();

        // Validation
        if (!firstName || !lastName || !phone || !dob || !designation || !gender || !country || !state || !city) {
            showError('All fields are required');
            return;
        }

        $.ajax({
            url: 'php/profile.php',
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({
                action: 'update',
                token: token,
                firstName: firstName,
                lastName: lastName,
                phone: phone,
                dob: dob,
                designation: designation,
                gender: gender,
                country: country,
                state: state,
                city: city
            }),
            success: function (response) {
                if (response.success) {
                    showSuccess(response.message || 'Profile updated successfully!');
                    $('#editProfileModal').removeClass('show');
                    setTimeout(function () {
                        loadProfile();
                    }, 1500);
                } else {
                    showError(response.message || 'Failed to update profile');
                }
            },
            statusCode: {
                400: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Invalid input');
                    } catch (e) {
                        showError('Invalid input. Please check your data.');
                    }
                },
                401: function (xhr) {
                    showError('Session expired. Please login again.');
                    performLogout();
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
            error: function (xhr, status, error) {
                if (xhr.status !== 400 && xhr.status !== 401 && xhr.status !== 500) {
                    showError('Network error. Please try again.');
                }
            }
        });
    });

    // Logout button handlers
    $('#logoutBtn, #logoutBtnMobile').on('click', function () {
        performLogout();
    });

    function performLogout() {
        $.ajax({
            url: 'php/profile.php',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'logout',
                token: token
            }),
            complete: function () {
                localStorage.removeItem('sessionToken');
                window.location.href = 'login.html';
            }
        });
    }

    function loadProfile() {
        // Show skeleton, hide content
        $('#profileSkeleton').addClass('show');
        $('#profileContent').hide();
        $('#statsSkeleton').addClass('show');
        $('#statsContent').hide();

        setSectionLoading('about');
        setSectionLoading('education');
        setSectionLoading('experience');
        setSectionLoading('portfolio');
        setSectionLoading('projects');
        setSectionLoading('skills');
        setSectionLoading('certifications');

        $.ajax({
            url: 'php/profile.php',
            type: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            data: JSON.stringify({
                action: 'fetch',
                token: token
            }),
            success: function (response) {
                if (response.success) {
                    const data = response.data;

                    // Display Profile
                    const firstName = data.firstName || '';
                    const lastName = data.lastName || '';
                    const fullName = (firstName + ' ' + lastName).trim();

                    $('#displayFullName').text(fullName || 'User Profile');
                    $('#displayDesignation').text(data.designation || '-');
                    $('#displayLocation').text((data.city && data.state) ? `${data.city}, ${data.state}` : '-');
                    $('#displayEmail').text(data.email || '-');
                    $('#displayPhone').text(data.phone || '-');
                    $('#displayDOB').text(formatDate(data.dob) || '-');

                    // Stats
                    $('#profileVisits').text('0');
                    $('#companyVisits').text('0');
                    $('#statsProfileVisits').text('0');
                    $('#statsCompanyVisits').text('0');
                    $('#statsOngoingCourses').text('0');
                    $('#statsCompletedCourses').text('0');

                    // Populate Edit Form
                    $('#editFirstName').val(firstName);
                    $('#editLastName').val(lastName);
                    $('#editEmail').val(data.email || '');
                    $('#editPhone').val(data.phone || '');
                    $('#editDOB').val(data.dob || '');
                    $('#editDesignation').val(data.designation || '');
                    $('#editGender').val(data.gender || '');
                    $('#editCountry').val(data.country || '');
                    $('#editState').val(data.state || '');
                    $('#editCity').val(data.city || '');

                    applySectionsData(data.sections || {});

                    // Hide skeleton, show content
                    $('#profileSkeleton').removeClass('show');
                    $('#profileContent').show();
                    $('#statsSkeleton').removeClass('show');
                    $('#statsContent').show();
                } else {
                    showError(response.message || 'Failed to load profile');
                }
            },
            statusCode: {
                401: function (xhr) {
                    showError('Your session has expired.');
                    performLogout();
                },
                404: function (xhr) {
                    showError('Profile not found');
                },
                500: function (xhr) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        showError(response.message || 'Server error');
                    } catch (e) {
                        showError('Server error. Service temporarily unavailable.');
                    }
                }
            },
            error: function (xhr) {
                if (xhr.status !== 401 && xhr.status !== 404 && xhr.status !== 500) {
                    showError('Network error. Please check your connection.');
                }
            }
        });
    }

    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function clearMessages() {
        $('#errorMessage').addClass('d-none');
        $('#successMessage').addClass('d-none');
        $('#errorText').empty();
        $('#successText').empty();
    }

    function showError(message) {
        $('#successMessage').addClass('d-none');
        $('#errorMessage').removeClass('d-none');
        $('#errorText').text(message);
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function showSuccess(message) {
        $('#errorMessage').addClass('d-none');
        $('#successMessage').removeClass('d-none');
        $('#successText').text(message);
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    // ========== PROFILE SECTIONS ==========

    const API_BASE = 'php/profile.php';

    function applySectionsData(sections) {
        const aboutData = sections.about || { content: '' };
        renderAboutMeContent(aboutData);

        renderEducationList(sections.education || []);
        renderExperienceList(sections.experience || []);

        const portfolioData = sections.portfolio || { website_url: '', linkedin_url: '', github_url: '', twitter_url: '' };
        renderPortfolioLinks(portfolioData);
        populatePortfolioForm(portfolioData);

        renderProjectsList(sections.projects || []);

        const skillsData = sections.skills || { hard_skills: [], soft_skills: [], interests: [] };
        renderSkillsDisplay(skillsData);
        populateSkillsForm(skillsData);

        renderCertificationsList(sections.certifications || []);
    }

    // Attach event listeners to all edit/add buttons
    function attachEventListeners() {
        // Edit buttons
        document.querySelectorAll('.section-edit-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const section = this.getAttribute('data-section');
                toggleSectionForm(section);
            });
        });

        // Add buttons
        document.querySelectorAll('.section-add-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const section = this.getAttribute('data-section');
                toggleSectionForm(section);
            });
        });

        document.addEventListener('click', function (event) {
            const button = event.target.closest('.empty-add-btn');
            if (!button) return;
            const section = button.getAttribute('data-section');
            if (section) {
                toggleSectionForm(section);
            }
        });

        // Form submissions
        attachFormListeners();
    }

    function attachFormListeners() {
        // About Me
        const aboutForm = document.querySelector('.about-me-form');
        if (aboutForm) {
            aboutForm.addEventListener('submit', saveAboutMe);
        }

        // Education
        const educationForm = document.querySelector('.education-form');
        if (educationForm) {
            educationForm.addEventListener('submit', addEducationSubmit);
            educationForm.querySelector('[name="start_month"]')?.addEventListener('change', updateEducationDateDisplay);
        }

        // Experience
        const experienceForm = document.querySelector('.experience-form');
        if (experienceForm) {
            experienceForm.addEventListener('submit', addExperienceSubmit);
            experienceForm.querySelector('[name="currently_working"]')?.addEventListener('change', toggleEndDateFields);
        }

        // Portfolio
        const portfolioForm = document.querySelector('.portfolio-form');
        if (portfolioForm) {
            portfolioForm.addEventListener('submit', savePortfolioSubmit);
        }

        // Projects
        const projectsForm = document.querySelector('.projects-form');
        if (projectsForm) {
            projectsForm.addEventListener('submit', addProjectSubmit);
        }

        // Skills
        const skillsForm = document.querySelector('.skills-form');
        if (skillsForm) {
            skillsForm.addEventListener('submit', saveSkillsSubmit);
        }

        // Certifications
        const certificationsForm = document.querySelector('.certifications-form');
        if (certificationsForm) {
            certificationsForm.addEventListener('submit', addCertificationSubmit);
            certificationsForm.querySelector('[name="no_expiry"]')?.addEventListener('change', toggleExpiryField);
        }
    }

    // ============== About Me Section ==============
    function loadAboutMe() {
        setSectionLoading('about');
        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'fetch', section: 'about', token })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderAboutMeContent(data.data || {});
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function saveAboutMe(e) {
        e.preventDefault();
        const content = document.getElementById('aboutMeInput').value;

        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update', section: 'about', token, content })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showProfileSectionSuccess('About Me saved successfully');
                    loadAboutMe();
                    toggleSectionForm('about');
                } else {
                    showProfileSectionError(data.message);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    // ============== Education Section ==============
    function loadEducation() {
        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'fetch', section: 'education', token })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderEducationList(data.data);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function renderEducationList(education) {
        const container = document.querySelector('.education-list');
        if (!container) return;

        if (education.length === 0) {
            container.innerHTML = getSectionEmptyStateMarkup('education', 'fas fa-graduation-cap', 'Share your Educational Qualifications like your University and about your schooling', 'Add Qualification');
            return;
        }

        container.innerHTML = education.map(edu => `
            <div class="education-card">
                <div class="card-header">
                    <h4 class="card-title">${sanitize(edu.level)}</h4>
                    <span class="card-badge">${sanitize(edu.grade)}</span>
                </div>
                <div class="card-content">
                    <strong>${sanitize(edu.school_name)}</strong><br>
                    <small>${sanitize(edu.board)}</small>
                    <p class="card-meta">
                        <span><i class="fas fa-calendar"></i>${sanitize(edu.start_date)} - ${sanitize(edu.end_date)}</span>
                    </p>
                    <p>${sanitize(edu.summary)}</p>
                </div>
                <div class="card-actions">
                    <button class="card-btn" onclick="deleteEducationItem('${edu._id}')" title="Delete">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }

    function addEducationSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.token = token;

        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...data, action: 'update', section: 'education', operation: 'add' })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showProfileSectionSuccess('Education added successfully');
                    loadEducation();
                    e.target.reset();
                    toggleSectionForm('education');
                } else {
                    showProfileSectionError(data.message);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function deleteEducationItem(id) {
        if (confirm('Are you sure you want to delete this education?')) {
            fetch(`${API_BASE}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', section: 'education', operation: 'delete', token, id })
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showProfileSectionSuccess('Education deleted');
                        loadEducation();
                    } else {
                        showProfileSectionError(data.message);
                    }
                })
                .catch(err => showProfileSectionError(err));
        }
    }

    // ============== Experience Section ==============
    function loadExperience() {
        setSectionLoading('experience');
        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'fetch', section: 'experience', token })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderExperienceList(data.data);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function renderExperienceList(experience) {
        const container = document.querySelector('.experience-list');
        if (!container) return;

        if (experience.length === 0) {
            container.innerHTML = getSectionEmptyStateMarkup('experience', 'fas fa-briefcase', 'Show your work journey and achievements from previous roles.', 'Add Experience');
            return;
        }

        container.innerHTML = experience.map(exp => `
            <div class="experience-card">
                <div class="card-header">
                    <h4 class="card-title">${sanitize(exp.job_title)}</h4>
                    <span class="card-badge">${sanitize(exp.employment_type)}</span>
                </div>
                <div class="card-content">
                    <strong>${sanitize(exp.company)}</strong><br>
                    <small><i class="fas fa-map-marker-alt"></i> ${sanitize(exp.location)}</small>
                    <p class="card-meta">
                        <span><i class="fas fa-calendar"></i>${sanitize(exp.start_date)} - ${sanitize(exp.end_date)}</span>
                    </p>
                    <p>${sanitize(exp.summary)}</p>
                </div>
                <div class="card-actions">
                    <button class="card-btn" onclick="deleteExperienceItem('${exp._id}')" title="Delete">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }

    function addExperienceSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.currently_working = formData.getAll('currently_working').length > 0;
        data.token = token;

        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...data, action: 'update', section: 'experience', operation: 'add' })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showProfileSectionSuccess('Experience added successfully');
                    loadExperience();
                    e.target.reset();
                    toggleSectionForm('experience');
                } else {
                    showProfileSectionError(data.message);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function deleteExperienceItem(id) {
        if (confirm('Are you sure you want to delete this experience?')) {
            fetch(`${API_BASE}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', section: 'experience', operation: 'delete', token, id })
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showProfileSectionSuccess('Experience deleted');
                        loadExperience();
                    } else {
                        showProfileSectionError(data.message);
                    }
                })
                .catch(err => showProfileSectionError(err));
        }
    }

    function toggleEndDateFields(e) {
        const endDateRow = document.querySelector('.end-date-row');
        if (endDateRow) {
            endDateRow.style.display = e.target.checked ? 'none' : 'grid';
            if (e.target.checked) {
                document.querySelector('[name="end_month"]')?.removeAttribute('required');
                document.querySelector('[name="end_year"]')?.removeAttribute('required');
            } else {
                document.querySelector('[name="end_month"]')?.setAttribute('required', 'required');
                document.querySelector('[name="end_year"]')?.setAttribute('required', 'required');
            }
        }
    }

    function toggleExpiryField(e) {
        const expiryYearRow = document.querySelector('.expiry-year-row');
        if (expiryYearRow) {
            expiryYearRow.style.display = e.target.checked ? 'none' : 'grid';
            if (e.target.checked) {
                document.querySelector('[name="expiry_year"]')?.removeAttribute('required');
            } else {
                document.querySelector('[name="expiry_year"]')?.setAttribute('required', 'required');
            }
        }
    }

    // ============== Portfolio Section ==============
    function loadPortfolio() {
        setSectionLoading('portfolio');
        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'fetch', section: 'portfolio', token })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderPortfolioLinks(data.data);
                    populatePortfolioForm(data.data);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function renderPortfolioLinks(portfolio) {
        const container = document.querySelector('.portfolio-display');
        if (!container) return;

        const links = [];

        if (portfolio.website_url) links.push({
            url: portfolio.website_url,
            label: 'Website',
            icon: 'fas fa-globe'
        });
        if (portfolio.linkedin_url) links.push({
            url: portfolio.linkedin_url,
            label: 'LinkedIn',
            icon: 'fab fa-linkedin'
        });
        if (portfolio.github_url) links.push({
            url: portfolio.github_url,
            label: 'GitHub',
            icon: 'fab fa-github'
        });
        if (portfolio.twitter_url) links.push({
            url: portfolio.twitter_url,
            label: 'Twitter',
            icon: 'fab fa-twitter'
        });

        if (links.length === 0) {
            container.innerHTML = getSectionEmptyStateMarkup('portfolio', 'fas fa-link', 'Website\nGitHub\nTwitter', 'Add Portfolio');
            return;
        }

        container.innerHTML = links.map(link => `
            <a href="${sanitize(link.url)}" class="portfolio-link" target="_blank" rel="noopener">
                <i class="${link.icon}"></i>
                <span>${link.label}</span>
            </a>
        `).join('');
    }

    function populatePortfolioForm(portfolio) {
        const form = document.querySelector('.portfolio-form');
        if (form) {
            const websiteInput = form.querySelector('[name="website_url"]');
            const linkedinInput = form.querySelector('[name="linkedin_url"]');
            const githubInput = form.querySelector('[name="github_url"]');
            const twitterInput = form.querySelector('[name="twitter_url"]');

            if (websiteInput) websiteInput.value = portfolio.website_url || '';
            if (linkedinInput) linkedinInput.value = portfolio.linkedin_url || '';
            if (githubInput) githubInput.value = portfolio.github_url || '';
            if (twitterInput) twitterInput.value = portfolio.twitter_url || '';
        }
    }

    function savePortfolioSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.token = token;

        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...data, action: 'update', section: 'portfolio', operation: 'save' })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showProfileSectionSuccess('Portfolio saved successfully');
                    loadPortfolio();
                    toggleSectionForm('portfolio');
                } else {
                    showProfileSectionError(data.message);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    // ============== Projects Section ==============
    function loadProjects() {
        setSectionLoading('projects');
        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'fetch', section: 'projects', token })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderProjectsList(data.data);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function renderProjectsList(projects) {
        const container = document.querySelector('.projects-list');
        if (!container) return;

        if (projects.length === 0) {
            container.innerHTML = getSectionEmptyStateMarkup('projects', 'fas fa-code-branch', 'Highlight your best projects and what you contributed.', 'Add Project');
            return;
        }

        container.innerHTML = projects.map(project => `
            <div class="projects-card">
                <div class="card-header">
                    <h4 class="card-title">${sanitize(project.project_name)}</h4>
                    <span class="card-badge">${sanitize(project.role)}</span>
                </div>
                <div class="card-content">
                    <p>${sanitize(project.summary)}</p>
                    <a href="${sanitize(project.project_link)}" class="btn btn-sm btn-link" target="_blank" rel="noopener">
                        View Project <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
                <div class="card-actions">
                    <button class="card-btn" onclick="deleteProjectItem('${project._id}')" title="Delete">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }

    function addProjectSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.token = token;

        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...data, action: 'update', section: 'projects', operation: 'add' })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showProfileSectionSuccess('Project added successfully');
                    loadProjects();
                    e.target.reset();
                    toggleSectionForm('projects');
                } else {
                    showProfileSectionError(data.message);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function deleteProjectItem(id) {
        if (confirm('Are you sure you want to delete this project?')) {
            fetch(`${API_BASE}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', section: 'projects', operation: 'delete', token, id })
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showProfileSectionSuccess('Project deleted');
                        loadProjects();
                    } else {
                        showProfileSectionError(data.message);
                    }
                })
                .catch(err => showProfileSectionError(err));
        }
    }

    // ============== Skills Section ==============
    function loadSkills() {
        setSectionLoading('skills');
        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'fetch', section: 'skills', token })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderSkillsDisplay(data.data);
                    populateSkillsForm(data.data);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function renderSkillsDisplay(skills) {
        const container = document.querySelector('.skills-display');
        if (!container) return;

        let html = '';

        if (skills.hard_skills && skills.hard_skills.length > 0) {
            html += `
                <div class="skill-category-display">
                    <h4><i class="fas fa-code"></i> Hard Skills</h4>
                    <div class="skill-tags">
                        ${skills.hard_skills.map(s => `<span class="skill-tag">${sanitize(s)}</span>`).join('')}
                    </div>
                </div>
            `;
        }

        if (skills.soft_skills && skills.soft_skills.length > 0) {
            html += `
                <div class="skill-category-display">
                    <h4><i class="fas fa-heart"></i> Soft Skills</h4>
                    <div class="skill-tags">
                        ${skills.soft_skills.map(s => `<span class="skill-tag">${sanitize(s)}</span>`).join('')}
                    </div>
                </div>
            `;
        }

        if (skills.interests && skills.interests.length > 0) {
            html += `
                <div class="skill-category-display">
                    <h4><i class="fas fa-compass"></i> Area of Interest</h4>
                    <div class="skill-tags">
                        ${skills.interests.map(s => `<span class="skill-tag">${sanitize(s)}</span>`).join('')}
                    </div>
                </div>
            `;
        }

        if (!html) {
            html = getSectionEmptyStateMarkup('skills', 'fas fa-star', 'Add your hard skills, soft skills, and areas of interest.', 'Add Skills');
        }

        container.innerHTML = html;
    }

    function populateSkillsForm(skills) {
        const form = document.querySelector('.skills-form');
        if (form) {
            if (skills.hard_skills) {
                skills.hard_skills.forEach(skill => {
                    const checkbox = form.querySelector(`[name="hard_skills"][value="${skill}"]`);
                    if (checkbox) checkbox.checked = true;
                });
            }

            if (skills.soft_skills) {
                skills.soft_skills.forEach(skill => {
                    const checkbox = form.querySelector(`[name="soft_skills"][value="${skill}"]`);
                    if (checkbox) checkbox.checked = true;
                });
            }

            if (skills.interests) {
                skills.interests.forEach(skill => {
                    const checkbox = form.querySelector(`[name="interests"][value="${skill}"]`);
                    if (checkbox) checkbox.checked = true;
                });
            }
        }
    }

    function saveSkillsSubmit(e) {
        e.preventDefault();
        const hardSkills = Array.from(document.querySelectorAll('[name="hard_skills"]:checked')).map(c => c.value);
        const softSkills = Array.from(document.querySelectorAll('[name="soft_skills"]:checked')).map(c => c.value);
        const interests = Array.from(document.querySelectorAll('[name="interests"]:checked')).map(c => c.value);

        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update',
                section: 'skills',
                operation: 'save',
                token,
                hard_skills: hardSkills,
                soft_skills: softSkills,
                interests
            })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showProfileSectionSuccess('Skills saved successfully');
                    loadSkills();
                    toggleSectionForm('skills');
                } else {
                    showProfileSectionError(data.message);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    // ============== Certifications Section ==============
    function loadCertifications() {
        setSectionLoading('certifications');
        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'fetch', section: 'certifications', token })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderCertificationsList(data.data);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function renderCertificationsList(certifications) {
        const container = document.querySelector('.certifications-list');
        if (!container) return;

        if (certifications.length === 0) {
            container.innerHTML = getSectionEmptyStateMarkup('certifications', 'fas fa-certificate', 'Show your certificates and credentials to stand out.', 'Add Certification');
            return;
        }

        container.innerHTML = certifications.map(cert => `
            <div class="certifications-card">
                <div class="card-header">
                    <h4 class="card-title">${sanitize(cert.name)}</h4>
                </div>
                <div class="card-content">
                    <p><strong>${sanitize(cert.issuing_org)}</strong></p>
                    <small>ID: ${sanitize(cert.credential_id)}</small>
                    <p class="card-meta">
                        <span><i class="fas fa-calendar"></i>${cert.issue_year}${cert.no_expiry ? '' : ` - ${cert.expiry_year || 'Present'}`}</span>
                    </p>
                    <a href="${sanitize(cert.credential_link)}" class="btn btn-sm btn-link" target="_blank" rel="noopener">
                        View Certificate <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
                <div class="card-actions">
                    <button class="card-btn" onclick="deleteCertificationItem('${cert._id}')" title="Delete">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }

    function addCertificationSubmit(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.no_expiry = formData.getAll('no_expiry').length > 0;
        data.token = token;

        fetch(`${API_BASE}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...data, action: 'update', section: 'certifications', operation: 'add' })
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showProfileSectionSuccess('Certification added successfully');
                    loadCertifications();
                    e.target.reset();
                    toggleSectionForm('certifications');
                } else {
                    showProfileSectionError(data.message);
                }
            })
            .catch(err => showProfileSectionError(err));
    }

    function deleteCertificationItem(id) {
        if (confirm('Are you sure you want to delete this certification?')) {
            fetch(`${API_BASE}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update', section: 'certifications', operation: 'delete', token, id })
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showProfileSectionSuccess('Certification deleted');
                        loadCertifications();
                    } else {
                        showProfileSectionError(data.message);
                    }
                })
                .catch(err => showProfileSectionError(err));
        }
    }

    // ============== Utility Functions ==============
    function getSectionFormElement(section) {
        const sectionFormMap = {
            about: '.about-me-form',
            education: '.education-form',
            experience: '.experience-form',
            portfolio: '.portfolio-form',
            projects: '.projects-form',
            skills: '.skills-form',
            certifications: '.certifications-form'
        };

        return document.querySelector(sectionFormMap[section] || `.${section}-form`);
    }

    function getSectionModalTitle(section) {
        const titleMap = {
            about: 'Edit About Me',
            education: 'Add Education',
            experience: 'Add Experience',
            portfolio: 'Edit Portfolio',
            projects: 'Add Project',
            skills: 'Edit Skills',
            certifications: 'Add Certification'
        };

        return titleMap[section] || 'Edit Section';
    }

    function openSectionFormModal(section) {
        const form = getSectionFormElement(section);
        if (!form || !sectionFormModal || !sectionFormModalBody) return;

        if (activeSectionForm && activeSectionForm !== form) {
            closeSectionFormModal(false);
        }

        activeSectionForm = form;
        activeSectionFormParent = form.parentNode;
        activeSectionFormNextSibling = form.nextSibling;

        if (sectionFormModalTitle) {
            sectionFormModalTitle.textContent = getSectionModalTitle(section);
        }

        sectionFormModalBody.appendChild(form);
        form.style.display = 'block';
        sectionFormModal.classList.add('show');
    }

    function closeSectionFormModal(resetForm) {
        if (!sectionFormModal) return;

        if (activeSectionForm && activeSectionFormParent) {
            if (activeSectionFormNextSibling && activeSectionFormNextSibling.parentNode === activeSectionFormParent) {
                activeSectionFormParent.insertBefore(activeSectionForm, activeSectionFormNextSibling);
            } else {
                activeSectionFormParent.appendChild(activeSectionForm);
            }

            activeSectionForm.style.display = 'none';
            if (resetForm && typeof activeSectionForm.reset === 'function') {
                activeSectionForm.reset();
            }
        }

        sectionFormModal.classList.remove('show');
        activeSectionForm = null;
        activeSectionFormParent = null;
        activeSectionFormNextSibling = null;
    }

    function toggleSectionForm(section) {
        const form = getSectionFormElement(section);
        if (!form) return;

        if (activeSectionForm === form && sectionFormModal && sectionFormModal.classList.contains('show')) {
            closeSectionFormModal(false);
        } else {
            openSectionFormModal(section);
        }
    }

    function cancelEdit(section) {
        closeSectionFormModal(true);
    }

    window.cancelEdit = cancelEdit;
    window.deleteEducationItem = deleteEducationItem;
    window.deleteExperienceItem = deleteExperienceItem;
    window.deleteProjectItem = deleteProjectItem;
    window.deleteCertificationItem = deleteCertificationItem;

    function showProfileSectionSuccess(message) {
        showSuccess(message);
    }

    function getSectionEmptyStateMarkup(section, iconClass, description, actionLabel) {
        return `
            <div class="section-empty-cta">
                <i class="${iconClass}"></i>
                <p>${description}</p>
                <button type="button" class="empty-add-btn" data-section="${section}">${actionLabel}</button>
            </div>
        `;
    }

    function renderAboutMeContent(aboutData) {
        const aboutDisplay = document.getElementById('aboutMeDisplay');
        const aboutInput = document.getElementById('aboutMeInput');
        const aboutEmpty = document.querySelector('.about-empty-state');
        const content = (aboutData && aboutData.content ? aboutData.content : '').trim();

        if (aboutInput) {
            aboutInput.value = content;
        }

        if (content) {
            if (aboutDisplay) {
                aboutDisplay.style.display = 'block';
                aboutDisplay.textContent = content;
            }
            if (aboutEmpty) {
                aboutEmpty.style.display = 'none';
                aboutEmpty.innerHTML = '';
            }
        } else {
            if (aboutDisplay) {
                aboutDisplay.style.display = 'none';
                aboutDisplay.textContent = '';
            }
            if (aboutEmpty) {
                aboutEmpty.style.display = 'block';
                aboutEmpty.innerHTML = getSectionEmptyStateMarkup('about', 'fas fa-user-circle', 'Tell us about your background, skills, and key achievements.', 'Add About');
            }
        }
    }

    function setSectionLoading(section) {
        const loadingMarkup = `<div class="section-loading-skeleton"><div class="skeleton"></div><div class="skeleton"></div><div class="skeleton"></div></div>`;

        if (section === 'about') {
            const aboutDisplay = document.getElementById('aboutMeDisplay');
            const aboutEmpty = document.querySelector('.about-empty-state');
            if (aboutDisplay) {
                aboutDisplay.style.display = 'none';
            }
            if (aboutEmpty) {
                aboutEmpty.style.display = 'block';
                aboutEmpty.innerHTML = loadingMarkup;
            }
            return;
        }

        const containerMap = {
            education: '.education-list',
            experience: '.experience-list',
            portfolio: '.portfolio-display',
            projects: '.projects-list',
            skills: '.skills-display',
            certifications: '.certifications-list'
        };

        const selector = containerMap[section];
        if (!selector) return;
        const container = document.querySelector(selector);
        if (container) {
            container.innerHTML = loadingMarkup;
        }
    }

    function showProfileSectionError(message) {
        const msg = typeof message === 'string' ? message : 'An error occurred';
        showError(msg);
    }

    function sanitize(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function updateEducationDateDisplay() {
        // Placeholder for additional date display logic if needed
    }

    // Initialize profile sections event listeners (data loads with loadProfile)
    attachEventListeners();
});
