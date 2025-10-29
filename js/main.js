(function($) {
    "use strict";

    // Modern alerts loader: replace native alert("localhost says") with SweetAlert2
    (function setupModernAlerts() {
        function overrideAlert() {
            if (window.Swal && typeof window.Swal.fire === 'function') {
                const nativeAlert = window.alert;
                window._nativeAlert = nativeAlert; // keep a reference just in case
                window.alert = function(message) {
                    // Try to infer type
                    let text = '';
                    let icon = 'info';
                    let title = '';
                    try {
                        const parsed = typeof message === 'string' ? JSON.parse(message) : message;
                        if (parsed && typeof parsed === 'object') {
                            if (parsed.success === true) { icon = 'success'; title = 'Success'; }
                            if (parsed.success === false) { icon = 'error'; title = 'Failed'; }
                            text = parsed.message || JSON.stringify(parsed);
                        } else {
                            text = String(message);
                        }
                    } catch (e) {
                        text = String(message);
                        // Heuristics
                        if (/success/i.test(text)) { icon = 'success'; title = 'Success'; }
                        else if (/error|fail|invalid/i.test(text)) { icon = 'error'; title = 'Error'; }
                        else { icon = 'info'; title = 'Notice'; }
                    }

                    window.Swal.fire({
                        title: title || 'Notice',
                        text: text,
                        icon: icon,
                        confirmButtonColor: '#0d6efd',
                        background: '#1e1f24',
                        color: '#e9ecef',
                        customClass: {
                            popup: 'swal2-dark'
                        }
                    });
                };
            }
        }

        // If SweetAlert2 is not present, inject it
        if (!window.Swal) {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
            script.async = true;
            script.onload = overrideAlert;
            document.head.appendChild(script);

            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css';
            document.head.appendChild(link);
        } else {
            overrideAlert();
        }
    })();

    // Spinner
    var spinner = function() {
        setTimeout(function() {
            if ($('#spinner').length > 0) {
                $('#spinner').removeClass('show');
            }
        }, 1);
    };
    spinner();

    // Sidebar Toggler
    $('.sidebar-toggler').on('click', function() {
        $('.sidebar .content').toggleClass('open');
        return false;
    });

    document.querySelector(".sidebar-toggler").addEventListener("click", function () {
        document.querySelector(".sidebar").classList.toggle("open");
        document.querySelector(".content").classList.toggle("open");
    });

    // Chart color
    Chart.defaults.color = "#6C7293";
    Chart.defaults.borderColor = "#000000";

    // Sales Overview Chart
    var salesOverviewElement = $("#sales-overview").get(0);
    if (salesOverviewElement) {
        var ctx1 = salesOverviewElement.getContext("2d");
        var myChart1 = new Chart(ctx1, {
        type: "bar",
        data: {
            labels: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
            datasets: [{
                label: "Engine Components (₱)",
                data: [500, 1000, 1500, 2000, 2500, 3000, 3500],
                backgroundColor: "rgba(235, 22, 22, .7)"
            },
            {
                label: "Exhaust (₱)",
                data: [2500, 2600, 2700, 2800, 2900, 3000, 3100],
                backgroundColor: "rgba(235, 22, 22, .5)"
            },
            {
                label: "Tires (₱)",
                data: [1500, 1600, 1700, 1800, 1900, 2000, 2100],
                backgroundColor: "rgba(235, 22, 22, .7)"
            },
            {
                label: "Accessories (₱)",
                data: [1000, 1100, 1200, 1300, 1400, 1500, 1600],
                backgroundColor: "rgba(235, 22, 22, .5)"
            },
            {
                label: "Electrical Components (₱)",
                data: [150, 1000, 1500, 2000, 2500, 3000, 3500],
                backgroundColor: "rgba(235, 22, 22, .7)"
            },
            {
                label: "Mugs (₱)",
                data: [3000, 3100, 3200, 3300, 3400, 3500, 3600],
                backgroundColor: "rgba(235, 22, 22, .5)"
            },
            {
                label: "Oil (₱)",
                data: [160, 170, 180, 190, 200, 210, 220],
                backgroundColor: "rgba(235, 22, 22, .7)"
            }]
        },
        options: {
            responsive: true
        }
    });
    }

    // Sales & Revenue Chart
    var salesRevenueElement = $("#sales-revenue").get(0);
    if (salesRevenueElement) {
        var ctx2 = salesRevenueElement.getContext("2d");
        var myChart2 = new Chart(ctx2, {
        type: "line",
        data: {
            labels: ["January", "February", "March", "April", "May, June", "July"],
            datasets: [{
                label: "Sales",
                data: [15, 30, 45, 60, 75, 90, 105],
                backgroundColor: "rgba(235, 22, 22, .7)",
                fill: true
            },
            {
                label: "Revenue",
                data: [99, 125, 165, 150, 160, 180, 210],
                backgroundColor: "rgba(235, 22, 22, .5)",
                fill: true
            }]
        },
        options: {
            responsive: true
        }
    });
    }

    // Calendar
    $('#calendar').datetimepicker({
        inline: true,
        format: 'L'
    });

    // User Details Modal
    document.addEventListener('DOMContentLoaded', function () {
        const userDetailsModal = document.getElementById('userDetailsModal');
        if (userDetailsModal) {
            userDetailsModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const name = button.getAttribute('data-name') || 'N/A';
                const email = button.getAttribute('data-email') || 'N/A';
                const address = button.getAttribute('data-address') || 'N/A';
                const contact = button.getAttribute('data-contact') || 'N/A';
                const status = button.getAttribute('data-status') || 'N/A';
                const img = button.getAttribute('data-img') || 'default-user.png';

                const modalName = document.getElementById('modalName');
                const modalEmail = document.getElementById('modalEmail');
                const modalAddress = document.getElementById('modalAddress');
                const modalContact = document.getElementById('modalContact');
                const modalStatus = document.getElementById('modalStatus');
                const modalImage = document.getElementById('modalImage');

                if (modalName) modalName.textContent = name;
                if (modalEmail) modalEmail.textContent = email;
                if (modalAddress) modalAddress.textContent = address;
                if (modalContact) modalContact.textContent = contact;
                if (modalStatus) modalStatus.textContent = status;

                if (modalImage) {
                    modalImage.src = img;
                    modalImage.alt = `${name}'s Profile Picture`;
                }
            });
        }
    });

    // Sale Details Modal
    const saleDetailsModal = document.getElementById('saleDetailsModal');
    if (saleDetailsModal) {
        saleDetailsModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const customer = button.getAttribute('data-customer');
        const trn = button.getAttribute('data-trn');
        const amount = button.getAttribute('data-amount');
        const status = button.getAttribute('data-status');
        const paymentMethod = button.getAttribute('data-payment-method');
        const img = button.getAttribute('data-img');
        const time = button.getAttribute('data-time');
        const contact = button.getAttribute('data-contact');
        const address = button.getAttribute('data-address');
        const products = button.getAttribute('data-products');

        const modalSaleCustomer = document.getElementById('modalSaleCustomer');
        const modalSaleTrn = document.getElementById('modalSaleTrn');
        const modalSaleAmount = document.getElementById('modalSaleAmount');
        const modalSaleStatus = document.getElementById('modalSaleStatus');
        const modalSalePaymentMethod = document.getElementById('modalSalePaymentMethod');
        const modalSaleTime = document.getElementById('modalSaleTime');
        const modalSaleContact = document.getElementById('modalSaleContact');
        const modalSaleAddress = document.getElementById('modalSaleAddress');
        const modalSaleProducts = document.getElementById('modalSaleProducts');
        const modalSaleImage = document.getElementById('modalSaleImage');

        if (modalSaleCustomer) modalSaleCustomer.textContent = customer;
        if (modalSaleTrn) modalSaleTrn.textContent = trn;
        if (modalSaleAmount) modalSaleAmount.textContent = amount;
        if (modalSaleStatus) modalSaleStatus.textContent = status;
        if (modalSalePaymentMethod) modalSalePaymentMethod.textContent = paymentMethod;
        if (modalSaleTime) modalSaleTime.textContent = time;
        if (modalSaleContact) modalSaleContact.textContent = contact;
        if (modalSaleAddress) modalSaleAddress.textContent = address;
        if (modalSaleProducts) modalSaleProducts.textContent = products;
        if (modalSaleImage) modalSaleImage.src = img;
        });
    }

    // Role Display
    const roleElement = document.getElementById('role');
    if (roleElement) {
        roleElement.innerText = 'Admin';
    }

    // Search Functionality
    $(".search-barangay").on("keyup", function () {
        let value = $(this).val().toLowerCase();
        $(".dropdown-item").filter(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });

    // Enable Purok when Barangay is selected
    $("#barangay").on("change", function () {
        $("#purok").prop("disabled", $(this).val() === "");
    });

    // Eye Icon Password Toggle
    const eyeIcon = document.getElementById("eye-icon");
    const passwordField = document.getElementById("password");

    eyeIcon.addEventListener("click", function () {
        if (passwordField.type === "password") {
            passwordField.type = "text";
            eyeIcon.innerHTML = '<i class="fas fa-eye-slash"></i>';
        } else {
            passwordField.type = "password";
            eyeIcon.innerHTML = '<i class="fas fa-eye"></i>';
        }
    });

    // Profile Picture Preview
    document.getElementById('profilePicture').addEventListener('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewImage').src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    // Form Validation
    const profilePictureInput = document.getElementById('profilePicture');
    const continueButton = document.getElementById('continueButton');

    function checkFormValidity() {
        const fields = ['username', 'email', 'contactinfo', 'password', 'barangay', 'purok'];
        let isValid = true;

        fields.forEach(function(field) {
            const input = document.getElementById(field);
            if (input.value.trim() === '') {
                isValid = false;
                input.style.border = '2px solid red';
            } else {
                input.style.border = '';
            }
        });

        if (profilePictureInput && profilePictureInput.files.length === 0) {
            isValid = false;
        }

        if (isValid) {
            continueButton.disabled = false;
            continueButton.classList.remove('btn-secondary');
            continueButton.classList.add('btn-warning');
        } else {
            continueButton.disabled = true;
            continueButton.classList.remove('btn-warning');
            continueButton.classList.add('btn-secondary');
        }

        return isValid;
    }

    document.getElementById('signupForm').addEventListener('submit', function(event) {
        event.preventDefault();

        if (checkFormValidity()) {
            const successModal = new bootstrap.Modal(document.getElementById('successModal'));
            successModal.show();

            setTimeout(() => {
                this.submit();
            }, 2000);
        } else {
            const incompleteFormModal = new bootstrap.Modal(document.getElementById('incompleteFormModal'));
            incompleteFormModal.show();
        }
    });

    fields.forEach(function(field) {
        document.getElementById(field).addEventListener('input', checkFormValidity);
    });

    if (profilePictureInput) {
        profilePictureInput.addEventListener('change', checkFormValidity);
    }

    continueButton.addEventListener('click', function() {
        if (checkFormValidity()) {
            document.getElementById('signupForm').submit();
        }
    });

    // Search Button
    document.getElementById('searchButton').addEventListener('click', function () {
        const searchType = document.querySelector('input[name="searchType"]:checked').value;
        const searchValue = document.getElementById('searchInput').value;
        const rows = document.querySelectorAll('#productTableBody tr');

        rows.forEach(row => {
            const cell = row.querySelector(searchType === 'name' ? 'td:nth-child(2)' : 'td:nth-child(1)');
            if (cell.textContent.toLowerCase().includes(searchValue.toLowerCase())) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
    

    // Product Details Modal
    $(document).ready(function() {
        $('#productTableBody').on('click', 'button[data-bs-toggle="modal"]', function() {
            const productId = $(this).data('id');
            const productName = $(this).data('name');
            const productCategory = $(this).data('category');
            const productBrand = $(this).data('brand');
            const motorType = $(this).data('motortype');
            const quantity = $(this).data('quantity');
            const price = $(this).data('price');
            const details = $(this).data('details');
            const img = $(this).data('img');

            $('#modalProductID').text(productId);
            $('#modalProductName').text(productName);
            $('#modalProductCategory').text(productCategory);
            $('#modalProductBrand').text(productBrand);
            $('#modalMotorType').text(motorType);
            $('#modalProductQuantity').text(quantity);
            $('#modalProductPrice').text(price);
            $('#modalProductDetails').text(details);
            $('#modalProductImage').attr('src', img);

            $('#productModal').modal('show');
        });
    });
})(jQuery);