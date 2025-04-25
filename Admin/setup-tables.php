<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Setting up Database Tables</h1>";

// First create the posts table
echo "<h2>Setting up Posts Table</h2>";
include "sql/create_posts_table.php";

echo "<hr>";

// Then create the employees table (since it depends on posts)
echo "<h2>Setting up Employees Table</h2>";
include "sql/create_employees_table.php";

echo "<hr>";
echo "<a href='index.php' class='btn btn-primary'>Return to Dashboard</a>";
?> 