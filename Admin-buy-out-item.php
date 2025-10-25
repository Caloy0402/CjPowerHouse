<?php
session_start();
require_once 'dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: signin.php");
    exit();
}

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Get user data for profile image
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT first_name, last_name, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Fetch data from boughtoutproducts table
$sql = "SELECT * FROM boughtoutproducts";
$result = $conn->query($sql);

// Query to get the total cost of buyout products (sum of prices for products needing restock)
$totalCostQuery = "SELECT SUM(Price) AS total_cost FROM boughtoutproducts WHERE quantity <= 20";
$totalCostResult = mysqli_query($conn, $totalCostQuery);
$totalCostRow = mysqli_fetch_assoc($totalCostResult);
$totalCost = $totalCostRow['total_cost'] ?? 0; // If NULL, default to 0

// Query to get the total quantity of buyout products
$totalQuantityQuery = "SELECT SUM(Quantity) AS total_quantity FROM boughtoutproducts";
$totalQuantityResult = mysqli_query($conn, $totalQuantityQuery);
$totalQuantityRow = mysqli_fetch_assoc($totalQuantityResult);
$totalQuantity = $totalQuantityRow['total_quantity'] ?? 0; // If NULL, default to 0

// Add this query after your existing queries at the top of the file
$totalProductsQuery = "SELECT COUNT(product_id) AS total_products FROM boughtoutproducts WHERE quantity <= 20";
$totalProductsResult = mysqli_query($conn, $totalProductsQuery);
$totalProductsRow = mysqli_fetch_assoc($totalProductsResult);
$totalProducts = $totalProductsRow['total_products'] ?? 0;
?>




<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Jandi - Admin Dashboard</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicons -->
    <link href="Image/logo.png" rel="icon">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- icon font Stylesheet -->
     <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css"> 
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">

     <!--libraries stylesheet-->
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet">

    <!--customized Bootstrap Stylesheet-->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!--Template Stylesheet-->
    <link href="css/style.css" rel="stylesheet">

</head>
<body>
    <div class="container-fluid position-relative d-flex p-0">
       <!-- Spinner Start -->
    <div id="spinner" class="show bg-dark position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
    <img src="img/Loading.gif" alt="Loading..." style="width: 200px; height: 200px;" />
    </div>
    <!-- Spinner End -->
        <!-- Sidebar Start -->
     <!-- Sidebar Start -->
<div class="sidebar pe-4 pb-3">
    <nav class="navbar bg-secondary navbar-dark">
        <a href="Admin-Dashboard.php" class="navbar-brand mx-4 mb-3">
            <h3 class="text-primary"><i class="fa fa-user-edit me-2"></i>Cj P'House</h3>
        </a>
        <div class="d-flex align-items-center ms-4 mb-4">
            <div class="position-relative">
                <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" class="rounded-circle" style="width: 40px; height: 40px;">
                <div class="bg-success rounded-circle border border-2 border-white position-absolute end-0 bottom-0 p-1"></div>
            </div>
            <div class="ms-3">
                <h6 class="mb-0"><?= htmlspecialchars($user_data['first_name']) ?></h6>
                <span>Admin</span>
            </div>
        </div>
        <div class="navbar-nav w-100">
            <a href="Admin-Dashboard.php" class="nav-item nav-link active"><i class="fa fa-tachometer-alt me-2"></i>Dashboard</a>

            <div class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-users me-2"></i>Users
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Admin-AddUser.php" class="dropdown-item">Add Users</a>
                    <a href="Admin-ManageUser.php" class="dropdown-item">Manage Users</a>
                </div>
            </div>

            <!-- Updated Product Dropdown -->
            <div class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fa fa-th me-2"></i>Product
                </a>
                <div class="dropdown-menu bg-transparent border-0">
                    <a href="Admin-Stockmanagement.php" class="dropdown-item">Stock Management</a>
                    <a href="Admin-buy-out-item.php" class="dropdown-item">Buy-out Item</a>
                    <a href="Admin-ReturnedItems.php" class="dropdown-item">Returned Item</a>
                </div>
            </div>

            <a href="Admin-OrderLogs.php" class="nav-item nav-link"><i class="fa fa-shopping-cart me-2"></i>Order Logs</a>
            <a href="Admin-SalesReport.php" class="nav-item nav-link"><i class="fa fa-file-alt me-2"></i>Sales Report</a>
            <a href="Admin-StaffLogs.php" class="nav-item nav-link"><i class="fa fa-user-clock me-2"></i>Staff Logs</a>
            <a href="Admin-RescueLogs.php" class="nav-item nav-link"><i class="fa fa-tools me-2"></i>Rescue Logs</a>

            </div>
        </div>
    </nav>
</div>
<!-- Sidebar End -->

       
            <div class="content">
                <!--Navbar Start-->
                   <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top 
                   px-4 py-0">
                        <a href="Admin-Dashboard.php" class="navbar-brand d-flex d-lg-none me-4">
                            <h2 class="text-primary mb-0"><i class="fa fa-user-edit"></i></h2>
                        </a>
                        <a href="#" class="sidebar-toggler flex-shrink-0">
                            <i class="fa fa-bars"></i>

                        <div class="navbar-nav align-items-center ms-auto">
                <?php include 'admin_notifications.php'; ?>
                <?php include 'admin_rescue_notifications.php'; ?>
                <?php include 'admin_user_notifications.php'; ?>
                            <div class="nav-item dropdown">
                        <div class="dropdown-menu dropdown-menu-end bg-secondary 
                        border-0 rounded-0 rounded-bottom m-0">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/johanns.jpg" alt="User Profile"
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Johanns send you a 
                                    message</h6>
                                    <small>5 minutes ago</small>
                            </div>
                        </div>
                        </a>
                         <hr class="dropdown-divider">
                         <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/carlo.jpg" alt=""
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Carlo send you a 
                                    message</h6>
                                    <small>10 minutes ago</small>
                            </div>
                        </div>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item">
                            <div class="d-flex aligns-items-center">
                                <img src="img/alquin.jpg" alt=""
                                class="rounded-circle" style="width: 40px; height: 
                                40px;">
                                <div class="ms-2">
                                    <h6 class="fw-normal mb-0">Alquin send you a 
                                    message</h6>
                                    <small>15 minutes ago</small>
                            </div>
                        </div>
                        </a>
                        <hr class="dropdown-divider">
                        <a href="#" class="dropdown-item text-center">See all 
                        Messages</a>
                    </div>
                </div>
                
                <div class="nav-item dropdown">
                <a href="" class="nav-link dropdown-toggle" 
                data-bs-toggle="dropdown">
                <img src="<?= $user_data['profile_image'] ? (strpos($user_data['profile_image'], 'uploads/') === 0 ? $user_data['profile_image'] : 'uploads/' . $user_data['profile_image']) : 'img/jandi.jpg' ?>" alt="" class="rounded-circle me-lg-2" 
                alt="" style="width: 40px; height: 40px;">
                <span class="d-none d-lg-inline"><?= htmlspecialchars($user_data['first_name']) ?></span>
                </a>
                <div class="dropdown-menu dropdown-menu-end bg-dark border-0 rounded-3 shadow-lg m-0" style="min-width: 200px;">
                <div class="dropdown-header text-light border-bottom border-secondary">
                    <small class="text-muted">Account</small>
                </div>
                <a href="Admin-Profile.php" class="dropdown-item text-light d-flex align-items-center py-2">
                    <i class="fas fa-user me-2 text-primary"></i>
                    <span>Profile</span>
                </a>
                <a href="logout.php" class="dropdown-item text-light d-flex align-items-center py-2">
                    <i class="fas fa-sign-out-alt me-2 text-danger"></i>
                    <span>Log out</span>
                </a> 
                </div> 
                </div>
            </div>
         </nav>
         <!--Navbar End-->


     <!-- Sales & Revenue Start -->
     <div class="container-fluid pt-4 px-4">
    <div class="row g-4 justify-content-center"> 
        <div class="col-md-4">
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 w-100">
                <i class="fa fa-money-bill-wave fa-3x text-primary"></i>
                <div class="ms-3">
                    <p class="mb-2">Total Cost of Products Needing Restock</p>
                    <h6 class="mb-0">₱<?php echo number_format($totalCost, 2); ?></h6>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="bg-secondary rounded d-flex align-items-center justify-content-between p-4 w-100">
                <i class="fa fa-cart-arrow-down fa-3x text-success"></i>
                <div class="ms-3">
                    <p class="mb-2">Products Needing Restock</p>
                    <h6 class="mb-0"><?php echo $totalProducts; ?></h6>
                </div>
            </div>
        </div>
        
    </div>
</div>
        <!-- Sales & Revenue End -->


<!-- Bought Out Products Table -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary text-light rounded p-4">
        <h2 class="mb-4 text-center">Bought Out Products</h2> <!-- Center the title -->

        <div class="table-responsive">
            <table class="table table-dark table-bordered table-hover text-center"> <!-- Center all text -->
                <thead class="table-primary text-dark text-center"> <!-- Center column headers -->
                    <tr>
                        <th>Select</th>
                        <th>Product ID</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Brand</th>
                        <th>Motor Type</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Weight</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $result->fetch_assoc()) : ?>
                    <tr>
                        <td><input type="checkbox" name="selected_products[]" value="<?= $row['product_id'] ?>"></td>
                        <td><?= $row['product_id'] ?></td>
                        <td><?= $row['product_name'] ?></td>
                        <td><?= $row['category'] ?></td>
                        <td><?= $row['brand'] ?></td>
                        <td><?= $row['motor_type'] ?></td>
                        <td><?= $row['quantity'] ?></td>
                        <td>₱<?= number_format($row['price'], 2) ?></td>
                        <td><?= $row['Weight'] ?></td>
                        
                        <!-- Stock Status -->
                        <td>
                            <?php
                            $qty = $row['quantity'];
                            if ($qty >= 21) {
                                echo '<span class="badge bg-success">Good</span>';
                            } elseif ($qty >= 10 && $qty <= 20) {
                                echo '<span class="badge bg-warning text-dark">Low Stock</span>';
                            } elseif ($qty >= 2 && $qty <= 9) {
                                echo '<span class="badge bg-warning text-dark">Critical Stock</span>';
                            } else {
                                echo '<span class="badge bg-danger">Out of Stock</span>';
                            }
                            ?>
                        </td>

                        <!-- Edit Button -->
                        <td>
                            <button class="btn btn-primary btn-sm edit-btn" 
                                    data-id="<?= $row['product_id'] ?>" 
                                    data-name="<?= $row['product_name'] ?>" 
                                    data-quantity="<?= $row['quantity'] ?>" 
                                    data-price="<?= $row['price'] ?>" 
                                    data-image="<?= $row['image_path'] ?>" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editModal">
                                Edit
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <!-- Action Buttons -->
        <div class="text-center mt-3">
            <button id="addToProducts" class="btn btn-success">Add to Products</button>
        </div>
    </div>
</div>



<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
            <h5 class="modal-title text-dark text-center w-100" id="editModalLabel">RESTOCK</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <input type="hidden" id="editProductId" name="product_id">

                    <!-- Image Preview -->
                    <div class="text-center mb-3">
                        <img id="editProductImage" src="" alt="Product Image" width="100">
                    </div>

                    <!-- Product Name as Label -->
                    <div class="mb-3">
                        <label>Product Name:</label>
                        <p id="editProductName" class="fw-bold text-dark mb-0"></p> <!-- Label instead of input box -->
                    </div>

                   <!-- Quantity (Editable) -->
                    <div class="mb-3">
                        <label class="text-dark">Quantity:</label>
                        <input type="number" id="editQuantity" name="quantity" class="form-control text-white bg-dark" required>
                    </div>

                    <!-- Price (Editable) -->
                    <div class="mb-3">
                        <label class="text-dark">Price:</label>
                        <input type="number" step="0.01" id="editPrice" name="price" class="form-control text-white bg-dark" required>
                    </div>

                    <!-- Submit Button -->
                    <div class="d-flex justify-content-center">
                        <button type="submit" class="btn btn-success">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

 <!--Buy out Functions end-->


<!--Footer Start-->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded-top p-4">
        <div class="row">
            <div class="col-12 col-sm-6 text-center text-sm-start">
                &copy; <a href="#">Cj PowerHouse</a>, All Right Reserved.
            </div> 
            <div class="col-12 col-sm-6 text-center text-sm-end">
                Design By: <a href="">Team Jandi</a>
            </div>
        </div>
    </div>
</div>
<!--Footer End-->
    </div>   
</div>

    

   <!--javascript Libraries-->
   <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
   <script src="lib/chart/Chart.min.js"></script>
   <script src="lib/easing/easing.min.js"></script>
   <script src="lib/waypoints/waypoints.min.js"></script>
   <script src="lib/owlcarousel/owl.carousel.min.js"></script>
   <script src="lib/tempusdominus/js/moment.min.js"></script>
   <script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
   <script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>
   
   <script>
    (function(){
        function override(){
            if (window.Swal && typeof window.Swal.fire==='function'){
                var native = window.alert;
                window.alert = function(msg){
                    var text = ''+msg;
                    var icon = /success/i.test(text)?'success':(/fail|error|invalid/i.test(text)?'error':'info');
                    window.Swal.fire({title: icon==='success'?'Success':(icon==='error'?'Error':'Notice'), text: text, icon: icon, confirmButtonColor:'#0d6efd'});
                };
            }
        }
        if (!window.Swal){
            var s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js'; s.async=true; s.onload=override; document.head.appendChild(s);
            var l=document.createElement('link'); l.rel='stylesheet'; l.href='https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css'; document.head.appendChild(l);
        } else { override(); }
    })();
   </script>

    <!-- Template Javascript -->
    <script src="js/buyout.js">
    </script>
</body>
</html> 