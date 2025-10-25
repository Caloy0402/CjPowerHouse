document.addEventListener("DOMContentLoaded", function () {
    // Ensure SweetAlert2 is available on this page
    function ensureSwal(callback) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            try { callback(); } catch (e) { console.error(e); }
            return;
        }
        // inject once
        if (!document.getElementById('swal2-script-cdn')) {
            const s = document.createElement('script');
            s.id = 'swal2-script-cdn';
            s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
            s.async = true;
            s.onload = function() { try { callback(); } catch (e) { console.error(e); } };
            document.head.appendChild(s);
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css';
            document.head.appendChild(link);
        } else {
            // script already loading; poll until available
            const iv = setInterval(function(){
                if (window.Swal && typeof window.Swal.fire === 'function') {
                    clearInterval(iv);
                    try { callback(); } catch (e) { console.error(e); }
                }
            }, 100);
        }
    }

    // Spinner
    setTimeout(function () {
        let spinner = document.getElementById("spinner");
        if (spinner) {
            spinner.classList.remove("show");
        }
    }, 1);

    // Sidebar Toggler
    let sidebarToggler = document.querySelector(".sidebar-toggler");
    if (sidebarToggler) {
        sidebarToggler.addEventListener("click", function () {
            document.querySelector(".sidebar").classList.toggle("open");
            document.querySelector(".content").classList.toggle("open");
        });
    }

    // Search button - perform server-side search (no pagination reset needed)
    const searchBtn = document.getElementById('searchButton');
    if (searchBtn) {
        searchBtn.addEventListener('click', function () {
            const searchType = document.querySelector('input[name="searchType"]:checked').value;
            const searchValue = document.getElementById('searchInput').value.trim();
            const params = new URLSearchParams(window.location.search);
            params.set('q', searchValue);
            params.set('type', searchType === 'name' ? 'name' : 'id');
            const status = document.getElementById('stockFilter');
            if (status) params.set('status', status.value);
            window.location.search = params.toString();
        });
    }

    // Product image upload
    const productImageInput = document.getElementById("productImage");
    if (productImageInput) {
        productImageInput.addEventListener("change", function(event) {
            let fileName = event.target.files.length > 0 ? event.target.files[0].name : "Click to upload image";
            if (document.getElementById("file-name")) {
                document.getElementById("file-name").textContent = fileName;
            }
        });
    }

    // Client-side validation for Add Product form
    const productForm = document.getElementById('productForm');
    if (productForm) {
        productForm.addEventListener('submit', function(e){
            const missing = [];
            const name = document.getElementById('productName');
            const qty = document.getElementById('productQuantity');
            const price = document.getElementById('productPrice');
            const brand = document.getElementById('productBrand');
            const category = document.getElementById('productCategory');
            const motorType = document.getElementById('motorType');
            const kg = document.getElementById('productWeightKg');
            const grams = document.getElementById('productWeightGrams');
            const image = document.getElementById('productImage');

            function markInvalid(el){ if (!el) return; el.classList.add('is-invalid'); el.addEventListener('input', ()=> el.classList.remove('is-invalid'), { once:true }); }
            function showTinyTip(el, msg){
                if (!el) return;
                // Use Bootstrap tooltip if available
                try {
                    el.setAttribute('data-bs-toggle','tooltip');
                    el.setAttribute('data-bs-placement','top');
                    el.setAttribute('data-bs-original-title', msg);
                    var tip = bootstrap.Tooltip.getInstance(el);
                    if (!tip) tip = new bootstrap.Tooltip(el, { trigger: 'manual' });
                    tip.setContent && tip.setContent({ '.tooltip-inner': msg });
                    tip.show();
                    setTimeout(()=>{ try { tip.hide(); } catch(_){} }, 2500);
                    el.addEventListener('input', ()=> { try { tip.hide(); } catch(_){} }, { once:true });
                } catch (_) {
                    // Fallback: simple title attribute
                    el.title = msg;
                }
            }

            if (!name || name.value.trim()==='') { missing.push('Product Name'); markInvalid(name); }
            if (!qty || Number(qty.value)<=0) { missing.push('Quantity (> 0)'); markInvalid(qty); }
            if (!price || Number(price.value)<=0) { missing.push('Price (> 0)'); markInvalid(price); }
            if (!brand || brand.value.trim()==='') { missing.push('Brand'); markInvalid(brand); }
            if (!category || category.value.trim()==='') { missing.push('Category'); markInvalid(category); }
            if (!motorType || motorType.value.trim()==='') { missing.push('Motor Type'); markInvalid(motorType); }
            // For weights, use inline tooltip instead of modal
            let weightProblem = false;
            if (!kg || Number(kg.value) <= 0) { markInvalid(kg); showTinyTip(kg, 'Please enter weight in kg (> 0)'); weightProblem = true; }
            if (!grams || Number(grams.value) <= 0) { markInvalid(grams); showTinyTip(grams, 'Please enter weight in grams (> 0)'); weightProblem = true; }
            if (!image || image.files.length === 0) { missing.push('Product Image'); markInvalid(image); }

            if (missing.length > 0 || weightProblem) {
                e.preventDefault();
                // Only show modal for non-weight issues
                if (missing.length > 0) {
                    const msg = 'Missing or invalid: ' + missing.join(', ');
                    ensureSwal(function(){
                        Swal.fire({ title: 'Incomplete Data', text: msg, icon: 'warning', confirmButtonColor: '#0d6efd' });
                    });
                }
            }
        });
    }

    // Buy Out functionality
    const buyOutButton = document.getElementById("buyOutButton");
    if (buyOutButton) {
        // Disable button initially if no checkboxes are checked
        updateBuyOutButton();
        
        // Add event listeners to all checkboxes
        document.querySelectorAll(".product-checkbox").forEach(checkbox => {
            checkbox.addEventListener("change", updateBuyOutButton);
        });
        
        // Button click event
        buyOutButton.addEventListener("click", function() {
            console.log("Buy Out button clicked");
            let selectedProducts = [];
            
            document.querySelectorAll(".product-checkbox:checked").forEach(checkbox => {
                console.log("Found checked product:", checkbox.dataset.id);
                const priceText = String(checkbox.dataset.price || '').replace(/[₱,\s]/g, '');
                let productData = {
                    id: checkbox.dataset.id,
                    name: checkbox.dataset.name,
                    category: checkbox.dataset.category,
                    brand: checkbox.dataset.brand,
                    motortype: checkbox.dataset.motortype,
                    quantity: checkbox.dataset.quantity,
                    price: priceText,
                    weight: checkbox.dataset.weight,
                    img: checkbox.dataset.img
                };
                selectedProducts.push(productData);
            });
            
            console.log("Selected products:", selectedProducts);
            
            if (selectedProducts.length === 0) {
                console.log("No products selected");
                return;
            }
            
            // Send data to PHP via AJAX
            fetch("process_buyout.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ products: selectedProducts }),
            })
            .then(response => {
                console.log("Response status:", response.status);
                return response.json();
            })
            .then(data => {
                console.log("Response data:", data);
                if (data.success) {
                    document.querySelectorAll(".product-checkbox:checked").forEach(checkbox => {
                        checkbox.closest("tr").remove();
                    });
                    ensureSwal(function(){
                        Swal.fire({ title: 'Success', text: 'Products moved to Buy Out successfully!', icon: 'success', confirmButtonColor: '#0d6efd' });
                    });
                    updateBuyOutButton(); // Update button state after removing rows
                } else {
                    var msg = (data.message || "Error processing buyout. Try again.");
                    ensureSwal(function(){
                        Swal.fire({ title: 'Error', text: msg, icon: 'error', confirmButtonColor: '#0d6efd' });
                    });
                }
            })
            .catch(error => {
                console.error("Error during fetch:", error);
                ensureSwal(function(){
                    Swal.fire({ title: 'Error', text: 'An error occurred. Check console for details.', icon: 'error', confirmButtonColor: '#0d6efd' });
                });
            });
        });
    }
    
    function updateBuyOutButton() {
        const checkedProducts = document.querySelectorAll(".product-checkbox:checked");
        console.log("Checkbox count:", checkedProducts.length);
        if (buyOutButton) {
            buyOutButton.disabled = checkedProducts.length === 0;
        }
    }

    // Details button functionality
    $(document).ready(function() {
        // Event listener for the Details button
        $('#productTableBody').on('click', 'button[data-bs-toggle="modal"]', function() {
            // Get data attributes from the button
            const productId = $(this).data('id');
            const productName = $(this).data('name');
            const productCategory = $(this).data('category');
            const productBrand = $(this).data('brand');
            const motorType = $(this).data('motortype');
            const quantity = $(this).data('quantity');
            const price = $(this).data('price');
            const weight = $(this).data('weight');
            const img = $(this).data('img');

            // Populate the modal with the product details
            $('#modalProductID').text(productId);
            $('#modalProductName').text(productName);
            $('#modalProductCategory').text(productCategory);
            $('#modalProductBrand').text(productBrand);
            $('#modalMotorType').text(motorType);
            $('#modalProductQuantity').text(quantity);
            $('#modalProductPrice').text(price);
            $('#modalProductWeight').text(weight);
            $('#modalProductImage').attr('src', img);
        });
    });
});