<?php
// Set error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
error_log("store-management-report.php - Page loaded");

// Required files
require_once "layouts/config.php";
require_once "layouts/session.php";
require_once "layouts/helpers.php";
require_once "layouts/translations.php";

// Ensure the user is logged in and has appropriate permissions
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// Check for permission to view store reports
if (!hasPermission('view_reports') && !hasPermission('view_employees')) {
    $_SESSION['error_message'] = $_SESSION['lang'] == 'fr' ? 'Vous n\'avez pas la permission de voir ce rapport.' : 'You do not have permission to view this report.';
    header("location: index.php");
    exit;
}

// Initialize variables
$shops = [];
$errors = [];
$availableMonths = [];
$isHistoricalView = false;
$selectedDate = null;

// Get current month and year for default selection
$currentMonth = date('m');
$currentYear = date('Y');
$currentDate = date('Y-m-d');

// Get the selected month/year from GET parameters
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)$currentMonth;
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)$currentYear;

// Format the selected date as Y-m-d for database comparison
$selectedDate = date('Y-m-d', strtotime($selectedYear . '-' . $selectedMonth . '-01'));

// Check if this is a historical view (not current month)
$isHistoricalView = ($selectedMonth != $currentMonth || $selectedYear != $currentYear);

// Get available snapshot months
try {
    $snapshotsQuery = "
        SELECT DISTINCT 
            YEAR(snapshot_date) AS year, 
            MONTH(snapshot_date) AS month,
            snapshot_date
        FROM store_management_snapshots
        ORDER BY snapshot_date DESC
    ";
    $snapshotStmt = $pdo->prepare($snapshotsQuery);
    $snapshotStmt->execute();
    $availableMonths = $snapshotStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("store-management-report.php - Error getting available snapshots: " . $e->getMessage());
    // Continue execution, we'll handle missing snapshots later
}

try {
    if ($isHistoricalView) {
        // This is a historical view, get data from snapshots
        $query = "
            SELECT 
                id,
                snapshot_date,
                shop_id,
                shop_name,
                shop_location,
                manager_id,
                manager_name,
                manager_recruitment_date,
                manager_end_date,
                manager_recommender,
                manager_position,
                assistant_id,
                assistant_name,
                assistant_recruitment_date,
                assistant_end_date,
                assistant_position
            FROM store_management_snapshots
            WHERE DATE_FORMAT(snapshot_date, '%Y-%m') = ?
            ORDER BY shop_name ASC
        ";
        
        // Prepare and execute the query
        $stmt = $pdo->prepare($query);
        $stmt->execute([sprintf('%04d-%02d', $selectedYear, $selectedMonth)]);
        $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($shops)) {
            $errors[] = $_SESSION['lang'] == 'fr' 
                ? "Aucune donnée disponible pour $selectedMonth/$selectedYear. Les instantanés commencent à partir du mois suivant l'ouverture d'un nouveau mois." 
                : "No data available for $selectedMonth/$selectedYear. Snapshots start from the month following the opening of a new month.";
        }
        
        error_log("store-management-report.php - Successfully fetched " . count($shops) . " shops from snapshots for $selectedMonth/$selectedYear");
    } else {
        // This is current month view, get live data
        // Build query to get shop information with manager and assistant manager details
        $query = "
            SELECT 
                s.id AS shop_id, 
                s.name AS shop_name, 
                s.location AS shop_location,
                
                -- Manager information using aggregation to deal with GROUP BY
                MAX(manager.id) AS manager_id,
                MAX(manager.full_name) AS manager_name,
                MAX(manager.recruitment_date) AS manager_recruitment_date,
                MAX(manager.end_of_service_date) AS manager_end_date,
                MAX(manager_rec.name) AS manager_recommender,
                MAX(manager_post.title) AS manager_position,
                
                -- Assistant Manager information using aggregation
                MAX(am.id) AS assistant_id,
                MAX(am.full_name) AS assistant_name,
                MAX(am.recruitment_date) AS assistant_recruitment_date,
                MAX(am.end_of_service_date) AS assistant_end_date,
                MAX(am_post.title) AS assistant_position,
                
                -- Set current date as snapshot_date
                ? AS snapshot_date
                
            FROM 
                shops s
                
                -- Left join to get the manager using employee_shops and specific post_id
                LEFT JOIN employee_shops es_mgr ON s.id = es_mgr.shop_id
                LEFT JOIN employees manager ON es_mgr.employee_id = manager.id 
                    AND manager.post_id = '04b5ce3e-1aaf-11f0-99a1-cc28aa53b74d'
                LEFT JOIN posts manager_post ON manager.post_id = manager_post.id
                LEFT JOIN recommenders manager_rec ON manager.recommended_by_id = manager_rec.id
                
                -- Assistant manager joins
                LEFT JOIN employee_shops es_am ON s.id = es_am.shop_id
                LEFT JOIN employees am ON es_am.employee_id = am.id
                    AND am.post_id = 'cf0ca194-1abc-11f0-99a1-cc28aa53b74d'
                LEFT JOIN posts am_post ON am.post_id = am_post.id
            
            GROUP BY s.id, s.name, s.location
            ORDER BY s.name ASC
        ";
        
        // Prepare and execute the query
        $stmt = $pdo->prepare($query);
        $stmt->execute([$currentDate]);
        $shops = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("store-management-report.php - Successfully fetched " . count($shops) . " shops from live data");
    }
} catch (PDOException $e) {
    error_log("store-management-report.php - Database error: " . $e->getMessage());
    $errors[] = $_SESSION['lang'] == 'fr' ? "Erreur de base de données: " : "Database error: " . $e->getMessage();
}

// Page title
$page_title = $_SESSION['lang'] == 'fr' ? 'Rapport de Gestion des Magasins' : 'Store Management Report';
$page_description = $_SESSION['lang'] == 'fr' ? 'Vue d\'ensemble des gérants et adjoints pour chaque magasin' : 'Overview of managers and assistant managers for each store';

// Month names
$month_names = [
    1 => $_SESSION['lang'] == 'fr' ? 'Janvier' : 'January',
    2 => $_SESSION['lang'] == 'fr' ? 'Février' : 'February',
    3 => $_SESSION['lang'] == 'fr' ? 'Mars' : 'March',
    4 => $_SESSION['lang'] == 'fr' ? 'Avril' : 'April',
    5 => $_SESSION['lang'] == 'fr' ? 'Mai' : 'May',
    6 => $_SESSION['lang'] == 'fr' ? 'Juin' : 'June',
    7 => $_SESSION['lang'] == 'fr' ? 'Juillet' : 'July',
    8 => $_SESSION['lang'] == 'fr' ? 'Août' : 'August',
    9 => $_SESSION['lang'] == 'fr' ? 'Septembre' : 'September',
    10 => $_SESSION['lang'] == 'fr' ? 'Octobre' : 'October',
    11 => $_SESSION['lang'] == 'fr' ? 'Novembre' : 'November',
    12 => $_SESSION['lang'] == 'fr' ? 'Décembre' : 'December'
];
?>

<?php include 'layouts/head-main.php'; ?>

<head>
    <title><?php echo $page_title; ?> | <?php echo $_SESSION['lang'] == 'fr' ? 'Système de Gestion des Employés' : 'Employee Management System'; ?></title>
    <?php include 'layouts/head.php'; ?>
    
    <!-- DataTables CSS -->
    <link href="assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />
    
    <style>
        .duration-badge {
            font-size: 0.85em;
            padding: 0.35em 0.65em;
        }
        
        .card-title-desc {
            margin-top: 0;
        }
        
        .filter-form {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background-color: #f8f9fa;
            border-radius: 0.25rem;
            border: 1px solid #e9ecef;
        }
        
        .table-card {
            border-radius: 4px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .table-card .card-header {
            border-radius: 4px 4px 0 0;
            background-color: #f8f9fa;
            padding: 15px 20px;
        }
        
        .table-responsive {
            scrollbar-width: thin;
        }
        
        .table th {
            vertical-align: middle;
        }
        
        .snapshot-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            z-index: 1;
        }
    </style>
    
    <?php include 'layouts/head-style.php'; ?>
</head>

<?php include 'layouts/body.php'; ?>

<!-- Begin page -->
<div id="layout-wrapper">

    <?php include 'layouts/menu.php'; ?>
    
    <?php include 'layouts/header.php'; ?>

    <!-- ============================================================== -->
    <!-- Start right Content here -->
    <!-- ============================================================== -->
    <div class="main-content">

        <div class="page-content">
            <div class="container-fluid">

                <!-- start page title -->
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 class="mb-sm-0 font-size-18">
                                <?php echo $page_title; ?>
                                <?php if ($isHistoricalView): ?>
                                    <span class="badge bg-info"><?php echo $month_names[$selectedMonth] . ' ' . $selectedYear; ?></span>
                                <?php endif; ?>
                            </h4>

                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li class="breadcrumb-item"><a href="index.php"><?php echo $_SESSION['lang'] == 'fr' ? 'Tableau de Bord' : 'Dashboard'; ?></a></li>
                                    <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- end page title -->

                <?php include 'layouts/notification.php'; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Month selection form -->
                <div class="row">
                    <div class="col-12">
                        <div class="filter-form">
                            <form method="GET" action="store-management-report.php" class="row g-3 align-items-center">
                                <div class="col-md-4">
                                    <label for="month-year" class="form-label">
                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Sélectionner un mois' : 'Select Month'; ?>
                                    </label>
                                    <div class="input-group">
                                        <select class="form-select" id="month-year" name="month_year" onchange="handleMonthSelect(this.value)">
                                            <option value="current" <?php echo !$isHistoricalView ? 'selected' : ''; ?>>
                                                <?php echo $_SESSION['lang'] == 'fr' ? 'Mois actuel (Données en direct)' : 'Current Month (Live Data)'; ?>
                                            </option>
                                            <?php foreach ($availableMonths as $availMonth): ?>
                                                <option value="<?php echo $availMonth['year'] . '-' . $availMonth['month']; ?>" 
                                                        <?php echo ($selectedMonth == $availMonth['month'] && $selectedYear == $availMonth['year']) ? 'selected' : ''; ?>>
                                                    <?php echo $month_names[$availMonth['month']] . ' ' . $availMonth['year']; ?> 
                                                    <?php echo $_SESSION['lang'] == 'fr' ? '(Instantané)' : '(Snapshot)'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-primary" type="submit">
                                            <?php echo $_SESSION['lang'] == 'fr' ? 'Appliquer' : 'Apply'; ?>
                                        </button>
                                    </div>
                                    <input type="hidden" name="month" id="month-input" value="<?php echo $selectedMonth; ?>">
                                    <input type="hidden" name="year" id="year-input" value="<?php echo $selectedYear; ?>">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Results table -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <?php if ($isHistoricalView): ?>
                                <div class="snapshot-badge">
                                    <span class="badge rounded-pill bg-warning fs-6 px-3 py-2">
                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Vue Historique' : 'Historical View'; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="card-title mb-0">
                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Résultats du Rapport' : 'Report Results'; ?>
                                        <?php if ($isHistoricalView): ?>
                                            - <?php echo $month_names[$selectedMonth] . ' ' . $selectedYear; ?>
                                        <?php else: ?>
                                            - <?php echo $_SESSION['lang'] == 'fr' ? 'Mois Actuel' : 'Current Month'; ?>
                                        <?php endif; ?>
                                    </h4>
                                    <p class="card-title-desc">
                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Vue d\'ensemble des magasins avec gérants et adjoints' : 'Overview of stores with managers and assistant managers'; ?>
                                        <?php if ($isHistoricalView): ?>
                                            <span class="text-muted">
                                                (<?php echo $_SESSION['lang'] == 'fr' ? 'Données archivées' : 'Historical data'; ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="text-success">
                                                (<?php echo $_SESSION['lang'] == 'fr' ? 'Données en direct' : 'Live data'; ?>)
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($shops)): ?>
                                    <div class="alert alert-info mb-0">
                                        <?php echo $_SESSION['lang'] == 'fr' ? 'Aucun magasin trouvé.' : 'No stores found.'; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table id="store-report-table" class="table table-striped table-bordered dt-responsive nowrap" style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                                            <thead>
                                                <tr>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Magasin' : 'Store'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Gérant' : 'Manager'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Date d\'embauche' : 'Date of Employment'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Recommandé par' : 'Recommender'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Durée d\'emploi' : 'Employment Duration'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Adjoint' : 'Assistant Manager'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Date d\'embauche' : 'Date of Employment'; ?></th>
                                                    <th><?php echo $_SESSION['lang'] == 'fr' ? 'Durée d\'emploi' : 'Employment Duration'; ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($shops as $shop): ?>
                                                    <?php
                                                    // Set the reference date for calculating duration
                                                    $referenceDate = $isHistoricalView ? $shop['snapshot_date'] : date('Y-m-d');
                                                    
                                                    // Calculate employment duration for manager
                                                    $manager_duration = "";
                                                    if (!empty($shop['manager_recruitment_date'])) {
                                                        $recruit_date = new DateTime($shop['manager_recruitment_date']);
                                                        
                                                        if (!empty($shop['manager_end_date'])) {
                                                            // If end date exists, calculate duration until that date
                                                            $end_date = new DateTime($shop['manager_end_date']);
                                                            $interval = $recruit_date->diff($end_date);
                                                        } else {
                                                            // Otherwise, calculate duration until reference date
                                                            $today = new DateTime($referenceDate);
                                                            $interval = $recruit_date->diff($today);
                                                        }
                                                        
                                                        $years = $interval->y;
                                                        $months = $interval->m;
                                                        
                                                        if ($years > 0) {
                                                            $manager_duration = $years . ' ' . ($_SESSION['lang'] == 'fr' ? 'an(s)' : 'year(s)');
                                                            if ($months > 0) {
                                                                $manager_duration .= ', ' . $months . ' ' . ($_SESSION['lang'] == 'fr' ? 'mois' : 'month(s)');
                                                            }
                                                        } else {
                                                            $manager_duration = $months . ' ' . ($_SESSION['lang'] == 'fr' ? 'mois' : 'month(s)');
                                                        }
                                                    }
                                                    
                                                    // Calculate employment duration for assistant manager
                                                    $assistant_duration = "";
                                                    if (!empty($shop['assistant_recruitment_date'])) {
                                                        $recruit_date = new DateTime($shop['assistant_recruitment_date']);
                                                        
                                                        if (!empty($shop['assistant_end_date'])) {
                                                            // If end date exists, calculate duration until that date
                                                            $end_date = new DateTime($shop['assistant_end_date']);
                                                            $interval = $recruit_date->diff($end_date);
                                                        } else {
                                                            // Otherwise, calculate duration until reference date
                                                            $today = new DateTime($referenceDate);
                                                            $interval = $recruit_date->diff($today);
                                                        }
                                                        
                                                        $years = $interval->y;
                                                        $months = $interval->m;
                                                        
                                                        if ($years > 0) {
                                                            $assistant_duration = $years . ' ' . ($_SESSION['lang'] == 'fr' ? 'an(s)' : 'year(s)');
                                                            if ($months > 0) {
                                                                $assistant_duration .= ', ' . $months . ' ' . ($_SESSION['lang'] == 'fr' ? 'mois' : 'month(s)');
                                                            }
                                                        } else {
                                                            $assistant_duration = $months . ' ' . ($_SESSION['lang'] == 'fr' ? 'mois' : 'month(s)');
                                                        }
                                                    }
                                                    
                                                    // Format recruitment and end dates
                                                    $manager_date = !empty($shop['manager_recruitment_date']) 
                                                                    ? date('Y-m-d', strtotime($shop['manager_recruitment_date'])) 
                                                                    : "";
                                                    
                                                    $manager_end = !empty($shop['manager_end_date']) 
                                                                    ? date('Y-m-d', strtotime($shop['manager_end_date'])) 
                                                                    : "";
                                                    
                                                    $assistant_date = !empty($shop['assistant_recruitment_date']) 
                                                                     ? date('Y-m-d', strtotime($shop['assistant_recruitment_date'])) 
                                                                     : "";
                                                                     
                                                    $assistant_end = !empty($shop['assistant_end_date']) 
                                                                    ? date('Y-m-d', strtotime($shop['assistant_end_date'])) 
                                                                    : "";
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($shop['shop_name']); ?></strong>
                                                            <?php if (!empty($shop['shop_location'])): ?>
                                                                <div class="small text-muted"><?php echo htmlspecialchars($shop['shop_location']); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <!-- Manager column -->
                                                        <td>
                                                            <?php if (!empty($shop['manager_name'])): ?>
                                                                <?php echo htmlspecialchars($shop['manager_name']); ?>
                                                                <?php if (!empty($shop['manager_position'])): ?>
                                                                    <div class="small text-muted"><?php echo htmlspecialchars($shop['manager_position']); ?></div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted font-italic"><?php echo $_SESSION['lang'] == 'fr' ? 'Non assigné' : 'Not assigned'; ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo $manager_date; ?></td>
                                                        <td><?php echo htmlspecialchars($shop['manager_recommender'] ?? '—'); ?></td>
                                                        <td>
                                                            <?php if (!empty($manager_duration)): ?>
                                                                <span class="badge <?php echo !empty($shop['manager_end_date']) ? 'bg-warning' : 'bg-success'; ?> duration-badge">
                                                                    <?php echo $manager_duration; ?>
                                                                    <?php if (!empty($shop['manager_end_date'])): ?>
                                                                        <br/><small><?php echo $_SESSION['lang'] == 'fr' ? 'Fin: ' : 'End: '; ?><?php echo date('Y-m-d', strtotime($shop['manager_end_date'])); ?></small>
                                                                    <?php endif; ?>
                                                                </span>
                                                            <?php else: ?>
                                                                —
                                                            <?php endif; ?>
                                                        </td>
                                                        <!-- Assistant Manager column -->
                                                        <td>
                                                            <?php if (!empty($shop['assistant_name'])): ?>
                                                                <?php echo htmlspecialchars($shop['assistant_name']); ?>
                                                                <?php if (!empty($shop['assistant_position'])): ?>
                                                                    <div class="small text-muted"><?php echo htmlspecialchars($shop['assistant_position']); ?></div>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted font-italic"><?php echo $_SESSION['lang'] == 'fr' ? 'Non assigné' : 'Not assigned'; ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo $assistant_date; ?></td>
                                                        <td>
                                                            <?php if (!empty($assistant_duration)): ?>
                                                                <span class="badge <?php echo !empty($shop['assistant_end_date']) ? 'bg-warning' : 'bg-info'; ?> duration-badge">
                                                                    <?php echo $assistant_duration; ?>
                                                                    <?php if (!empty($shop['assistant_end_date'])): ?>
                                                                        <br/><small><?php echo $_SESSION['lang'] == 'fr' ? 'Fin: ' : 'End: '; ?><?php echo date('Y-m-d', strtotime($shop['assistant_end_date'])); ?></small>
                                                                    <?php endif; ?>
                                                                </span>
                                                            <?php else: ?>
                                                                —
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'layouts/footer.php'; ?>
    </div>
    <!-- end main content-->

</div>
<!-- END layout-wrapper -->

<?php include 'layouts/right-sidebar.php'; ?>

<?php include 'layouts/vendor-scripts.php'; ?>

<!-- Required datatable js -->
<script src="assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>

<!-- Responsive datatable js -->
<script src="assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>

<!-- Datatable init js -->
<script>
    $(document).ready(function() {
        // Initialize DataTable with pagination
        var table = $('#store-report-table').DataTable({
            responsive: true,
            paging: true,
            searching: true,
            info: true,
            fixedHeader: true,
            language: {
                <?php if ($_SESSION['lang'] == 'fr'): ?>
                search: 'Rechercher:',
                info: 'Affichage de _TOTAL_ entrées',
                infoEmpty: 'Aucune entrée à afficher',
                infoFiltered: '(filtré à partir de _MAX_ entrées au total)',
                zeroRecords: 'Aucun enregistrement correspondant trouvé',
                emptyTable: 'Aucune donnée disponible dans le tableau'
                <?php else: ?>
                search: 'Search:',
                info: 'Showing _TOTAL_ entries',
                infoEmpty: 'No entries to display',
                infoFiltered: '(filtered from _MAX_ total entries)',
                zeroRecords: 'No matching records found',
                emptyTable: 'No data available in table'
                <?php endif; ?>
            }
        });
        
        // Function to handle month selection and set the hidden inputs
        function handleMonthSelect(value) {
            if (value === 'current') {
                // Current month selected
                document.getElementById('month-input').value = <?php echo $currentMonth; ?>;
                document.getElementById('year-input').value = <?php echo $currentYear; ?>;
            } else {
                // Historical month selected (format: YYYY-MM)
                const parts = value.split('-');
                document.getElementById('year-input').value = parts[0];
                document.getElementById('month-input').value = parts[1];
            }
        }
    });
</script>

<!-- App js -->
<script src="assets/js/app.js"></script>

</body>
</html>