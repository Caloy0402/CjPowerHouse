<?php
require_once 'dbconn.php';

// Fetch barangays from the database
$query = "SELECT id, barangay_name FROM barangays"; // Change 'barangays' to your actual table name
$barangayResult = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>signup</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link rel="icon" type="image/png" href="Image/logo.png">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css"> 
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">

    <!-- Libraries Stylesheet -->
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">

    <!-- Customized Bootstrap Stylesheet -->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="css/style.css" rel="stylesheet">
</head>
<body>
<div class="container-fluid position-relative d-flex p-0">
    <!-- Spinner Start -->
    <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
    <img src="img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
    </div>
    <!-- Spinner End -->

    <div class="container d-flex justify-content-center align-items-center" style="min-height: 100vh;">
    <div class="col-12 col-sm-8 col-md-6 col-lg-5">
        <div class="bg-secondary rounded p-4">
            <h3 class="text-center mb-3">Sign Up</h3>


            
            <form id="signupForm" action="signup.php" method="POST" enctype="multipart/form-data">
    <!-- Profile Picture Upload Box -->
    <div class="mb-3 text-center">
        <label for="profilePicture" class="form-label">Add Profile Image</label>
        <input type="file" id="profilePicture" name="profilePicture" accept="image/*" class="form-control mt-2">
    </div>

    <!-- First Name -->
    <div class="form-floating mb-3">
        <input type="text" name="first_name" id="first_name" class="form-control" placeholder="First Name" required>
        <label>First Name</label>
    </div>

    <!-- Middle Name -->
    <div class="form-floating mb-3">
        <input type="text" name="middle_name" id="middle_name" class="form-control" placeholder="Middle Name">
        <label>Middle Name</label>
    </div>

    <!-- Last Name -->
    <div class="form-floating mb-3">
        <input type="text" name="last_name" id="last_name" class="form-control" placeholder="Last Name" required>
        <label>Last Name</label>
    </div>

    <!-- Email -->
    <div class="form-floating mb-3">
        <input type="email" name="email" id="email" class="form-control" placeholder="Email" required>
        <label>Email</label>
    </div>

    <!-- Phone Number -->
    <div class="input-group mb-3">
    <div class="input-group-text bg-dark border-0 text-white">
        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/9/99/Flag_of_the_Philippines.svg/320px-Flag_of_the_Philippines.svg.png" 
            alt="PH Flag" class="img-fluid" style="width: 20px; height: auto; margin-right: 5px;">
        <select class="form-select bg-dark text-white border-0" style="width: 80px;">
            <option selected>+63</option>
        </select>
    </div>
    <input type="tel" name="contactinfo" id="contactinfo" class="form-control bg-dark text-white border-0 shadow-sm" placeholder="Phone Number" required>
</div>



    <!-- Password -->
    <div class="form-floating mb-3">
        <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
        <label>Password</label>
        <span class="eye-icon" id="eye-icon" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer;">
            <i class="fas fa-eye"></i>
        </span>
    </div>

    <!-- Barangay Dropdown -->
    <div class="form-floating mb-3">
        <select id="barangay" name="barangay" class="form-control" required>
            <option value="">Select Barangay</option>
            <?php
            if ($barangayResult->num_rows > 0) {
                while ($row = $barangayResult->fetch_assoc()) {
                    echo "<option value='" . $row['id'] . "'>" . $row['barangay_name'] . "</option>";
                }
            }
            ?>
        </select>
        <label for="barangay">Barangay</label>
    </div>

    <!-- Purok Textbox -->
    <div class="form-floating mb-3">
        <input type="text" id="purok" name="purok" class="form-control" placeholder="Enter Purok" required>
        <label for="purok">Purok</label>
    </div>

    <button type="submit" class="btn btn-primary py-3 w-100">Sign Up</button>
    <p class="text-center mt-3">Already have an account? <a href="signin.php">Login</a></p>
</form>
<!-- Form End -->

<!-- Modal for Incomplete Form Message -->
<div class="modal fade" id="incompleteFormModal" tabindex="-1" aria-labelledby="incompleteFormModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="incompleteFormModalLabel">Incomplete Form</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Unable to create user due to incomplete form. Please make sure all fields are filled out before submitting.</p>
      </div>
      <div class="modal-footer">
        <button type="button" id="continueButton" class="btn btn-secondary" data-bs-dismiss="modal" aria-label="Close" disabled>Continue</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal for Success Message -->
<div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="successModalLabel">Success</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Account successfully created!</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
    <!-- Sign Up End -->
</div>

<!-- JavaScript Libraries -->
<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="lib/chart/Chart.min.js"></script>
<script src="lib/easing/easing.min.js"></script>
<script src="lib/waypoints/waypoints.min.js"></script>
<script src="lib/owlcarousel/owl.carousel.min.js"></script>
<script src="lib/tempusdominus/js/moment.min.js"></script>
<script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
<script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>

<!-- Template JavaScript -->
<script src="js/main.js"></script>

</body>
</html>
