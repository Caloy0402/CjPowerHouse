document.addEventListener("DOMContentLoaded", function () {
    // Spinner
    setTimeout(function () {
        let spinner = document.getElementById("spinner");
        if (spinner) {
            spinner.classList.remove("show");
        }
    }, 1);

    // Sidebar Toggler (Check if element exists before attaching event)
    let sidebarToggler = document.querySelector(".sidebar-toggler");
    if (sidebarToggler) {
        sidebarToggler.addEventListener("click", function () {
            document.querySelector(".sidebar").classList.toggle("open");
            document.querySelector(".content").classList.toggle("open");
        });
    }

    // Admin Add-User Form Submission
    let addUserForm = document.getElementById("addUserForm");
    if (addUserForm) {
        addUserForm.addEventListener("submit", function (event) {
            event.preventDefault(); // Prevent page reload

            var formData = new FormData(this);

            fetch("add_user.php", {
                method: "POST",
                body: formData
            })
                .then(response => response.text())
                .then(data => {
                    document.getElementById("modalMessage").innerHTML = data; // Set modal message
                    var myModal = new bootstrap.Modal(document.getElementById("successModal"));
                    myModal.show();
                    addUserForm.reset(); // Reset form after success
                    document.getElementById("imagePreview").style.display = "none"; // Hide preview
                })
                .catch(error => console.error("Error:", error));
        });
    }

    // Preview Image
    window.previewImage = function (event) {
        var reader = new FileReader();
        reader.onload = function () {
            var output = document.getElementById("imagePreview");
            output.src = reader.result;
            output.style.display = "block";
        };
        reader.readAsDataURL(event.target.files[0]);
    };
});

document.getElementById('addUserForm').addEventListener('submit', function(event) {
    var password = document.getElementById('passwordInput').value;
    var confirmPassword = document.getElementById('confirmPasswordInput').value;
    var errorMessage = document.getElementById('error-message');
    
    if (password !== confirmPassword) {
        event.preventDefault(); // Prevent form submission
        errorMessage.style.display = 'block'; // Show error message
    } else {
        errorMessage.style.display = 'none'; // Hide error message
    }
});
