<?php
session_start();
// Include the database connection
require_once 'dbconn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: signin.php");
    exit();
}

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get user data for profile image
$user_id = $_SESSION['user_id'];
$user_query = $conn->prepare("SELECT first_name, last_name, profile_image FROM cjusers WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Handle form submission for adding a product
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['addProduct'])) {
    // Normalize inputs
    $productName = trim($_POST['productName'] ?? '');
    $quantity = (int)($_POST['productQuantity'] ?? 0);
    $price = (float)($_POST['productPrice'] ?? 0);
    $brand = trim($_POST['productBrand'] ?? '');
    $category = trim($_POST['productCategory'] ?? '');
    $motorType = trim($_POST['motorType'] ?? '');
    $weightKg = (float)($_POST['productWeightKg'] ?? 0);
    $weightGrams = (float)($_POST['productWeightGrams'] ?? 0);
    $weight = $weightKg + ($weightGrams/1000);

    $missing = [];
    if ($productName === '') $missing[] = 'Product Name';
    if ($quantity <= 0) $missing[] = 'Quantity (> 0)';
    if ($price <= 0) $missing[] = 'Price (> 0)';
    if ($brand === '') $missing[] = 'Brand';
    if ($category === '') $missing[] = 'Category';
    if ($motorType === '') $missing[] = 'Motor Type';
    if ($weight <= 0) $missing[] = 'Weight (> 0)';
    if (!isset($_FILES['productImage']) || $_FILES['productImage']['error'] !== 0) $missing[] = 'Product Image';

    if (!empty($missing)) {
        $msg = 'Missing: ' . implode(', ', $missing);
        echo "<script>(function(){var msg='" . addslashes($msg) . "';function show(){if(window.Swal){Swal.fire({title:'Incomplete Data',text:msg,icon:'warning',confirmButtonColor:'#0d6efd'});}else{setTimeout(function(){if(window.Swal){Swal.fire({title:'Incomplete Data',text:msg,icon:'warning',confirmButtonColor:'#0d6efd'});}else{alert(msg);}},500);} } if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',show);}else{show();}})();</script>";
    } else {
        // Duplicate check (case-insensitive by product name only)
        $dupSql = "SELECT COUNT(*) AS cnt From Products WHERE LOWER(ProductName)=LOWER(?)";
        if ($dupStmt = $conn->prepare($dupSql)) {
            $dupStmt->bind_param('s', $productName);
            $dupStmt->execute();
            $dupRes = $dupStmt->get_result();
            $dupCount = ($dupRes && ($row=$dupRes->fetch_assoc())) ? (int)$row['cnt'] : 0;
            $dupStmt->close();
        } else {
            $dupCount = 0;
        }

        if ($dupCount > 0) {
            echo "<script>(function(){var msg='This product is already exists.';function show(){if(window.Swal){Swal.fire({title:'Duplicate Product',text:msg,icon:'warning',confirmButtonColor:'#0d6efd'});}else{setTimeout(function(){if(window.Swal){Swal.fire({title:'Duplicate Product',text:msg,icon:'warning',confirmButtonColor:'#0d6efd'});}else{alert(msg);}},500);} } if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',show);}else{show();}})();</script>";
        } else {
            // Proceed with upload now that validations passed
            $imagePath = basename($_FILES['productImage']['name']);
            $targetDir = "uploads/";
            if (!is_dir($targetDir)) { mkdir($targetDir, 0777, true); }

            if (isset($_FILES['productImage']) && $_FILES['productImage']['error'] == 0) {
                $targetFile = $targetDir . basename($_FILES['productImage']['name']);
                if (!move_uploaded_file($_FILES['productImage']['tmp_name'], $targetFile)) {
                    echo "<script>(function(){function show(){if(window.Swal){Swal.fire({title:'Upload Failed',text:'Failed to move uploaded file.',icon:'error',confirmButtonColor:'#0d6efd'});}else{alert('Failed to move uploaded file.');} } if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',show);}else{show();}})();</script>";
                }
            }

            // Insert into Products table
            $stmt = $conn->prepare("INSERT INTO Products (ProductName, Quantity, Price, Brand, Category, MotorType, ImagePath, `Weight`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("siissssd", $productName, $quantity, $price, $brand, $category, $motorType, $imagePath, $weight);

            if ($stmt->execute()) {
                echo "<script>(function(){var msg='Product added successfully!';function show(){Swal && Swal.fire({title:'Success',text:msg,icon:'success',confirmButtonColor:'#0d6efd'});} if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',show);}else{show();}})();</script>";
            } else {
                echo "<script>(function(){var msg='Error: " . addslashes($stmt->error) . "';function show(){Swal && Swal.fire({title:'Error',text:msg,icon:'error',confirmButtonColor:'#0d6efd'});} if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',show);}else{show();}})();</script>";
            }

            $stmt->close();
        }
    }
}

// Fetch total products cost
$sqlTotalCost = "SELECT SUM(Price * Quantity) AS TotalCost From Products";
$resultTotalCost = $conn->query($sqlTotalCost);
$totalCost = $resultTotalCost->fetch_assoc()['TotalCost'] ?? 0;

$sqlTotalProducts = "SELECT SUM(quantity) AS TotalQuantity From Products";
$resultTotalProducts = $conn->query($sqlTotalProducts);
$totalProducts = $resultTotalProducts->fetch_assoc()['TotalQuantity'] ?? 0;

// Filter setup (no pagination)
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : 'All';
$searchQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$searchType = isset($_GET['type']) ? trim($_GET['type']) : 'name';

// Build WHERE based on stock status thresholds
$where = '';
if ($statusFilter === 'Good') {
    $where = "WHERE Quantity >= 21";
} elseif ($statusFilter === 'Low Stock') {
    $where = "WHERE Quantity BETWEEN 10 AND 20";
} elseif ($statusFilter === 'Critical Stock') {
    $where = "WHERE Quantity BETWEEN 2 AND 9";
} elseif ($statusFilter === 'Out of Stock') {
    $where = "WHERE Quantity <= 1";
}

// Append search where
if ($searchQuery !== '') {
    $field = ($searchType === 'id') ? 'ProductID' : 'ProductName';
    $condition = $field === 'ProductID' ? "CAST(ProductID AS CHAR) LIKE '%".$conn->real_escape_string($searchQuery)."%'"
                                         : "ProductName LIKE '%".$conn->real_escape_string($searchQuery)."%'";
    $where .= ($where === '' ? 'WHERE ' : ' AND ') . $condition;
}

// Fetch all products for display (include computed stock_status)
$sql = "SELECT *,
            CASE
                WHEN Quantity >= 21 THEN 'Good'
                WHEN Quantity BETWEEN 10 AND 20 THEN 'Low Stock'
                WHEN Quantity BETWEEN 2 AND 9 THEN 'Critical Stock'
                ELSE 'Out of Stock'
            END AS stock_status
        From Products
        $where
        ORDER BY ProductID DESC";
$result = $conn->query($sql);

$products = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
}

// No pagination needed

$conn->close();
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
    <link href="css/stockstyle.css" rel="stylesheet">

    <!-- SweetAlert2 (load early to avoid native alerts fallback) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

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

        <!--Content Start-->
            <div class="content">
                <!--Navbar Start-->
                   <nav class="navbar navbar-expand bg-secondary navbar-dark sticky-top
                   px-4 py-0">
                        <a href="Admin-Dashboard.php" class="navbar-brand d-flex d-lg-none me-4">
                            <h2 class="text-primary mb-0"><i class="fa fa-user-edit"></i></h2>
                        </a>
                        <a href="#" class="sidebar-toggler flex-shrink-0">
                            <i class="fa fa-bars"></i>
                        </a>
                        <div class="navbar-nav align-items-center ms-auto">
                <--?php include 'admin_notifications.php'; ?>
                <--?php include 'admin_rescue_notifications.php'; ?>
                <--?php include 'admin_user_notifications.php'; ?>
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

         


   <!-- Input Data Container -->
   <div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded p-4">
        <h4 class="mb-4">Input Data</h4>
        <form id="productForm" method="POST" enctype="multipart/form-data">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="productName" class="form-label">Product Name</label>
                    <input type="text" class="form-control" id="productName" name="productName" required>
                </div>
                <div class="col-md-6">
                    <label for="productQuantity" class="form-label">Quantity</label>
                    <input type="number" class="form-control" id="productQuantity" name="productQuantity" required>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="productPrice" class="form-label">Price</label>
                    <input type="number" class="form-control" id="productPrice" name="productPrice" required>
                </div>
                <div class="col-md-6">
                    <label for="productCategory" class="form-label">Category</label>
                    <select class="form-select" id="productCategory" name="productCategory" required>
                        <option value="">Select Category</option>
                        <option value="Oil">Oil</option>
                        <option value="Electrical Components">Electrical Components</option>
                        <option value="Tires">Tires</option>
                        <option value="Batteries">Batteries</option>
                        <option value="Accessories">Accessories</option>
                        <option value="Engine Components">Engine Components</option>
                        <option value="Exhaust">Exhaust</option>
                        <option value="Brakes">Brake Components</option>
                        <option value="Fuel Components">Fuel Components</option>
                        <option value="Bolts">Bolts & Nuts</option>
                        <option value="Motor Chains">Motor Chains</option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="productBrand" class="form-label">Brand</label>
                    <input type="text" class="form-control" id="productBrand" name="productBrand" required>
                </div>
                <div class="col-md-6">
                    <label for="motorType" class="form-label">Motor Type</label>
                    <input type="text" class="form-control" id="motorType" name="motorType" placeholder="Enter motorbike type (e.g., Mio, XRM)">
                </div>
            </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="productWeightKg" class="form-label">Weight (kg)</label>
                                <input type="number" class="form-control" id="productWeightKg" name="productWeightKg" step="0.01" value="0" required>
                            </div>
                            <div class="col-md-6">
                                <label for="productWeightGrams" class="form-label">Weight (grams)</label>
                                <input type="number" class="form-control" id="productWeightGrams" name="productWeightGrams" step="1" value="0" required>
                            </div>
                        </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="productImage" class="form-label">Product Image</label>
                    <input type="file" class="form-control" id="productImage" name="productImage" accept="image/*" required>
                </div>
            </div>
            <button type="submit" name="addProduct" class="btn btn-primary">Add Product</button>
        </form>
    </div>
</div>



        <!-- Data Grid View -->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded p-4">
        <!-- Search Section -->
        <h4 class="mb-4">Search Product</h4>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="mb-2">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="searchType" id="searchByName" value="name" checked>
                        <label class="form-check-label" for="searchByName">Search by Name</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="searchType" id="searchByID" value="id">
                        <label class="form-check-label" for="searchByID">Search by ID</label>
                    </div>
                </div>
                <input type="text" id="searchInput" class="form-control text-center" placeholder="Search by Name or ID" value="<?php echo htmlspecialchars($searchQuery); ?>">
            </div>
        </div>

        <div class="row justify-content-center mt-3">
            <div class="col-md-4">
                <button id="searchButton" class="btn btn-primary w-100">Search</button>
            </div>
        </div>


<!-- Data Grid View -->
<h4 class="mt-4 mb-2" style="text-align:center;">Product List</h4>

<!-- Stock Status Summary -->
<div class="mb-3 d-flex justify-content-center flex-wrap" style="gap: 15px;">
    <div class="d-flex align-items-center" style="gap: 8px;">
        <label class="text-white mb-0">Filter by Stock:</label>
        <select id="stockFilter" class="form-select form-select-sm" style="width:220px;">
            <option value="All" <?php echo ($statusFilter==='All')?'selected':''; ?>>All</option>
            <option value="Good" <?php echo ($statusFilter==='Good')?'selected':''; ?>>Good</option>
            <option value="Low Stock" <?php echo ($statusFilter==='Low Stock')?'selected':''; ?>>Low Stock</option>
            <option value="Critical Stock" <?php echo ($statusFilter==='Critical Stock')?'selected':''; ?>>Critical Stock</option>
            <option value="Out of Stock" <?php echo ($statusFilter==='Out of Stock')?'selected':''; ?>>Out of Stock</option>
        </select>
    </div>
    
    <!-- Stock Status Counts -->
    <div class="d-flex align-items-center" style="gap: 15px;">
        <span class="badge bg-success" style="font-size: 0.9rem; padding: 8px 12px;">
            Good: <?php echo count(array_filter($products, function($p) { return $p['Quantity'] >= 21; })); ?>
        </span>
        <span class="badge bg-warning text-dark" style="font-size: 0.9rem; padding: 8px 12px;">
            Low Stock: <?php echo count(array_filter($products, function($p) { return $p['Quantity'] >= 10 && $p['Quantity'] <= 20; })); ?>
        </span>
        <span class="badge bg-warning text-dark" style="font-size: 0.9rem; padding: 8px 12px;">
            Critical: <?php echo count(array_filter($products, function($p) { return $p['Quantity'] >= 2 && $p['Quantity'] <= 9; })); ?>
        </span>
        <span class="badge bg-danger" style="font-size: 0.9rem; padding: 8px 12px;">
            Out of Stock: <?php echo count(array_filter($products, function($p) { return $p['Quantity'] <= 1; })); ?>
        </span>
    </div>
</div>
<div style="display: flex; justify-content: center;">
    <div class="table-responsive" style="width: auto; max-height: 600px; overflow-y: auto;">
        <table class="table table-striped table-dark table-hover" style="margin: 0 auto; width: 100%;">
            <thead style="position: sticky; top: 0; background: #343a40; z-index: 1;">
                <tr style="text-align: center;">
                    <th>Select</th>
                    <th>Product ID</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Brand</th>
                    <th>Motor Type</th>
                    <th>Quantity</th>
                    <th>Price</th>
                    <th>Weight (kg)</th>
                    <th>Stock Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="productTableBody" style="text-align: center;">
                <?php foreach ($products as $product): ?>
                    <?php
                        $qty = $product['Quantity'];
                        if ($qty >= 21) {
                            $statusText = 'Good';
                        } elseif ($qty >= 10 && $qty <= 20) {
                            $statusText = 'Low Stock';
                        } elseif ($qty >= 2 && $qty <= 9) {
                            $statusText = 'Critical Stock';
                        } else {
                            $statusText = 'Out of Stock';
                        }
                    ?>
                    <tr style="text-align: center;" data-status="<?php echo $statusText; ?>">
                        <td>
                            <input type="checkbox" class="product-checkbox"
                                   data-id="<?php echo $product['ProductID']; ?>"
                                   data-name="<?php echo $product['ProductName']; ?>"
                                   data-quantity="<?php echo $product['Quantity']; ?>"
                                   data-price="<?php echo $product['Price']; ?>"
                                   data-brand="<?php echo $product['Brand']; ?>"
                                   data-category="<?php echo $product['Category']; ?>"
                                   data-motortype="<?php echo $product['MotorType']; ?>"
                                   data-weight="<?php echo $product['Weight']; ?>"
                                   data-img="<?php echo $product['ImagePath']; ?>">
                        </td>
                        <td><?php echo $product['ProductID']; ?></td>
                        <td><?php echo $product['ProductName']; ?></td>
                        <td><?php echo $product['Category']; ?></td>
                        <td><?php echo $product['Brand']; ?></td>
                        <td><?php echo $product['MotorType']; ?></td>
                        <td><?php echo $product['Quantity']; ?></td>
                        <td>₱<?php echo number_format($product['Price'], 2); ?></td>
                        <td><?php echo number_format($product['Weight'], 2); ?></td>

                        <!-- Stock Status -->
                        <td>
                                <?php
                                if ($statusText === 'Good') {
                                    echo '<span class="badge bg-success" style="font-size: 1rem; padding: 6px 12px;">Good</span>';
                                } elseif ($statusText === 'Low Stock') {
                                    echo '<span class="badge bg-warning text-dark" style="font-size: 1rem; padding: 6px 12px;">Low Stock</span>';
                                } elseif ($statusText === 'Critical Stock') {
                                    echo '<span class="badge bg-warning text-dark" style="font-size: 1rem; padding: 6px 12px;">Critical Stock</span>';
                                } else {
                                    echo '<span class="badge bg-danger" style="font-size: 1rem; padding: 6px 12px;">Out of Stock</span>';
                                }
                                ?>
                        </td>
                        <!-- Actions -->
                        <td>
                            <button class="btn btn-sm btn-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#productModal"
                                    data-id="<?php echo $product['ProductID']; ?>"
                                    data-name="<?php echo $product['ProductName']; ?>"
                                    data-category="<?php echo $product['Category']; ?>"
                                    data-brand="<?php echo $product['Brand']; ?>"
                                    data-motortype="<?php echo $product['MotorType']; ?>"
                                    data-quantity="<?php echo $product['Quantity']; ?>"
                                    data-price="<?php echo number_format($product['Price'], 2); ?>"
                                    data-weight="<?php echo number_format($product['Weight'], 2); ?>"
                                    data-img="uploads/<?php echo $product['ImagePath']; ?>">
                                Details
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Buy Out Button -->
<div style="text-align: center;">
  <button id="buyOutButton" class="btn btn-success mt-3">Buy Out</button>
</div>

<!-- Product Details Modal -->
<div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header d-flex justify-content-center">
                <h5 class="modal-title fw-bold" id="productModalLabel" style="color: #000;">Product Info</h5>
                <button type="button" class="btn-close position-absolute end-0 me-2" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Centered image -->
                <div class="text-center mb-3">
                    <img id="modalProductImage" src="" class="img-fluid" alt="Product Image"
                         style="max-width: 300px; max-height: 300px; object-fit: contain;">
                </div>
                <!-- Product details table -->
                <table class="table table-bordered">
                    <tbody>
                        <tr>
                            <th class="text-start align-middle fw-bold text-dark" style="width: 35%;">Product ID</th>
                            <td class="text-center align-middle fw-bold" id="modalProductID" style="width: 65%;"></td>
                        </tr>
                        <tr>
                            <th class="text-start align-middle fw-bold text-dark">Name</th>
                            <td class="text-center align-middle fw-bold" id="modalProductName"></td>
                        </tr>
                        <tr>
                            <th class="text-start align-middle fw-bold text-dark">Category</th>
                            <td class="text-center align-middle fw-bold" id="modalProductCategory"></td>
                        </tr>
                        <tr>
                            <th class="text-start align-middle fw-bold text-dark">Brand</th>
                            <td class="text-center align-middle fw-bold" id="modalProductBrand"></td>
                        </tr>
                        <tr>
                            <th class="text-start align-middle fw-bold text-dark">Motor Type</th>
                            <td class="text-center align-middle fw-bold" id="modalMotorType"></td>
                        </tr>
                        <tr>
                            <th class="text-start align-middle fw-bold text-dark">Quantity</th>
                            <td class="text-center align-middle fw-bold" id="modalProductQuantity"></td>
                        </tr>
                        <tr>
                            <th class="text-start align-middle fw-bold text-dark">Price</th>
                            <td class="text-center align-middle fw-bold">₱<span id="modalProductPrice"></span></td>
                        </tr>
                                        <tr>
                                            <th class="text-start align-middle fw-bold text-dark">Weight (kg)</th>
                                            <td class="text-center align-middle fw-bold"><span id="modalProductWeight"></span></td>
                                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
</div>

<!--Footer Start-->
<div class="container-fluid pt-4 px-4">
    <div class="bg-secondary rounded-top p-4">
        <div class="row">
            <div class="col-12 col-sm-6 text-center text-sm-start">
                            © <a href="#">Cj PowerHouse</a>, All Right Reserved.
            </div>
            <div class="col-12 col-sm-6 text-center text-sm-end">
                Design By: <a href="">Team Jandi</a>
            </div>
        </div>
    </div>
</div>
<!--Footer End-->

    </div>
    <!--Content End-->
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
    // Local page fallback: load SweetAlert2 and override alert
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
    <script src="js/stock.js"></script>
    <script>
    // Submit filter by changing URL parameter (server-side filtering with pagination)
    (function(){
        var filter = document.getElementById('stockFilter');
        if (!filter) return;
        filter.addEventListener('change', function(){
            var params = new URLSearchParams(window.location.search);
            params.set('status', filter.value);
            params.set('page', '1');
            window.location.search = params.toString();
        });
    })();
    </script>
</body>
</html>