document.addEventListener("DOMContentLoaded", function () {
    "use strict";

    // Spinner (Fixed)
    setTimeout(function () {
        let spinner = document.getElementById("spinner");
        if (spinner) {
            spinner.classList.remove("show");
        }
    }, 100); // Increased timeout for better visibility

    // Sidebar Toggler (Fixed)
    let sidebarToggler = document.querySelector(".sidebar-toggler");
    if (sidebarToggler) {
        sidebarToggler.addEventListener("click", function () {
            document.querySelector(".sidebar").classList.toggle("open");
            document.querySelector(".content").classList.toggle("open");
        });
    }

    // Chart Color Defaults
    Chart.defaults.color = "#6C7293";
    Chart.defaults.borderColor = "#000000";

    // Sales Overview Chart (Fixed)
    let salesOverviewCanvas = document.getElementById("sales-overview");
    if (salesOverviewCanvas) {
        let ctx1 = salesOverviewCanvas.getContext("2d");
        new Chart(ctx1, {
            type: "bar",
            data: {
                labels: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                datasets: [
                    {
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
                    }
                ]
            },
            options: {
                responsive: true
            }
        });
    }

    // Sales & Revenue Chart (Fixed)
    let salesRevenueCanvas = document.getElementById("sales-revenue");
    if (salesRevenueCanvas) {
        let ctx2 = salesRevenueCanvas.getContext("2d");
        new Chart(ctx2, {
            type: "line",
            data: {
                labels: ["January", "February", "March", "April", "May", "June", "July"],
                datasets: [
                    {
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
                    }
                ]
            },
            options: {
                responsive: true
            }
        });
    }

    // Open Modal and Load Product Details (Fixed)
    document.querySelectorAll(".edit-btn").forEach(button => {
        button.addEventListener("click", function () {
            let editProductId = document.getElementById("editProductId");
            let editProductName = document.getElementById("editProductName");
            let editQuantity = document.getElementById("editQuantity");
            let editPrice = document.getElementById("editPrice");
            let editProductImage = document.getElementById("editProductImage");
            editProductImage.style.maxWidth = "400px";
            editProductImage.style.width = "100%"; // Makes it responsive
            editProductImage.style.height = "auto"; // Keeps the aspect ratio
            editProductImage.style.display = "block"; // Centers the image
            editProductImage.style.margin = "10px auto"; // Adds spacing

            if (editProductId) editProductId.value = this.getAttribute("data-id");
            if (editProductName) editProductName.textContent = this.getAttribute("data-name");
            if (editQuantity) editQuantity.value = this.getAttribute("data-quantity");
            if (editPrice) editPrice.value = this.getAttribute("data-price");

            let imagePath = this.getAttribute("data-image") || "default-image.jpg";
            let basePath = "uploads/"; // Change this to your actual image folder
            document.getElementById("editProductImage").src = basePath + imagePath;
        });
    });

    // Submit Form via AJAX (Fixed)
    let editForm = document.getElementById("editForm");
    if (editForm) {
        editForm.addEventListener("submit", function (e) {
            e.preventDefault();

            let formData = new FormData(this);

            fetch("update_product.php", {
                method: "POST",
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                if (window.Swal) {
                    // Check if the response contains success indicators
                    const isSuccess = /successfully updated|restocked|success/i.test(data);
                    Swal.fire({ 
                        title: isSuccess ? 'Success' : 'Notice', 
                        text: data, 
                        icon: isSuccess ? 'success' : 'warning', 
                        confirmButtonColor: '#0d6efd' 
                    })
                    .then(()=> location.reload());
                } else {
                    alert(data);
                    location.reload();
                }
            })
            .catch(error => console.error("Error:", error));
        });
    }

    // Add to Products Button Click Event
    document.getElementById("addToProducts").addEventListener("click", function() {
        let selectedProducts = [];

        document.querySelectorAll("input[name='selected_products[]']:checked").forEach(checkbox => {
            let row = checkbox.closest("tr");
            const priceText = row.querySelector("td:nth-child(8)").textContent.replace(/[₱,\s]/g, '');
            selectedProducts.push({
                product_id: row.querySelector("td:nth-child(2)").textContent,
                product_name: row.querySelector("td:nth-child(3)").textContent,
                category: row.querySelector("td:nth-child(4)").textContent,
                brand: row.querySelector("td:nth-child(5)").textContent,
                motor_type: row.querySelector("td:nth-child(6)").textContent,
                quantity: row.querySelector("td:nth-child(7)").textContent,
                price: priceText,
                Weight: row.querySelector("td:nth-child(9)").textContent
            });
        });

        if (selectedProducts.length === 0) {
            if (window.Swal) {
                Swal.fire({ title: 'Notice', text: 'Please select at least one product.', icon: 'info', confirmButtonColor: '#0d6efd' });
            } else {
                alert("Please select at least one product.");
            }
            return;
        }

        fetch("add_to_products.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ products: selectedProducts })
        })
        .then(response => response.text())
        .then(data => {
            if (window.Swal) {
                // Check if the response contains success indicators
                const isSuccess = /success|added|moved/i.test(data);
                Swal.fire({ 
                    title: isSuccess ? 'Success' : 'Info', 
                    text: data, 
                    icon: isSuccess ? 'success' : 'info', 
                    confirmButtonColor: '#0d6efd' 
                })
                .then(()=> location.reload());
            } else {
                alert(data);
                location.reload();
            }
        })
        .catch(error => console.error("Error:", error));
    });
});