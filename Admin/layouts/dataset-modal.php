<?php
// This file contains the dataset selection modal that appears after login
// if the user has multiple datasets assigned

// Initialize SessionManager class and check if active dataset is set
require_once 'session-manager.php';
$sessionManager = new SessionManager();

// Get all datasets for the current user
$userDatasets = $sessionManager->getUserDatasets();

// Get the current active dataset
$activeDataset = $sessionManager->getActiveDataset();
$activeDatasetId = $activeDataset['id'] ?? '';

// If user has multiple datasets, we'll show the modal
$showDatasetModal = count($userDatasets) > 1;
?>

<!-- Dataset Selector Modal -->
<div class="modal fade" id="datasetSelectorModal" tabindex="-1" aria-labelledby="datasetSelectorModalLabel" data-bs-backdrop="static" aria-hidden="<?php echo $showDatasetModal ? 'false' : 'true'; ?>">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="datasetSelectorModalLabel">Select Dataset</h5>
                <?php if (!empty($activeDatasetId)): ?>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <?php endif; ?>
            </div>
            <div class="modal-body">
                <p>Please select the dataset you want to work with:</p>
                
                <div class="list-group">
                    <?php foreach ($userDatasets as $dataset): ?>
                    <a href="#" class="list-group-item list-group-item-action dataset-item <?php echo ($dataset['id'] === $activeDatasetId) ? 'active' : ''; ?>" 
                       data-dataset-id="<?php echo $dataset['id']; ?>">
                        <div class="d-flex w-100 justify-content-between">
                            <h5 class="mb-1"><?php echo htmlspecialchars($dataset['name']); ?></h5>
                            <?php if ($dataset['is_default']): ?>
                            <span class="badge bg-primary">Default</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($dataset['description'])): ?>
                        <p class="mb-1"><?php echo htmlspecialchars($dataset['description']); ?></p>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <?php if (!empty($activeDatasetId)): ?>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" id="selectDatasetBtn" <?php echo empty($activeDatasetId) ? 'disabled' : ''; ?>>
                    Select Dataset
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get the modal element
    const datasetModal = document.getElementById('datasetSelectorModal');
    
    // If modal should be shown automatically and is present
    <?php if ($showDatasetModal && empty($_SESSION['active_dataset_id'])): ?>
    // Show the modal when the page loads if no active dataset
    const bsDatasetModal = new bootstrap.Modal(datasetModal);
    bsDatasetModal.show();
    <?php endif; ?>
    
    // Dataset selection
    const datasetItems = document.querySelectorAll('.dataset-item');
    const selectBtn = document.getElementById('selectDatasetBtn');
    let selectedDatasetId = '<?php echo $activeDatasetId; ?>';
    
    // Add click event to all dataset items
    datasetItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all items
            datasetItems.forEach(i => i.classList.remove('active'));
            
            // Add active class to clicked item
            this.classList.add('active');
            
            // Store the selected dataset ID
            selectedDatasetId = this.dataset.datasetId;
            
            // Enable the select button
            selectBtn.removeAttribute('disabled');
        });
    });
    
    // Handle select button click
    selectBtn.addEventListener('click', function() {
        if (selectedDatasetId) {
            // Make AJAX request to set active dataset
            fetch('ajax-set-active-dataset.php?dataset_id=' + selectedDatasetId, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Reload the page to reflect the new dataset
                    window.location.reload();
                } else {
                    // Show error message
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while setting the active dataset.');
            });
        }
    });
});
</script> 