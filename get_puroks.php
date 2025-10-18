<?php
    header('Content-Type: application/json');
    require_once 'dbconn.php'; // connect your database here

    $sql = "SELECT purok_id, purok_name FROM puroks";
    $result = $conn->query($sql);

    if ($result) {
        $puroks = array();
        while($row = $result->fetch_assoc()) {
            $puroks[] = $row;
        }
        echo json_encode($puroks);
    } else {
        echo json_encode(array("error" => "Unable to fetch puroks: " . $conn->error));
    }

    $conn->close();
?>