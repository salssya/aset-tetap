// Initialize DataTable with responsive design
$(document).ready(function() {
    let table = $('#myTable').DataTable({
        responsive: true,
        autoWidth: false,
        columnDefs: [
            {
                targets: '_all',
                className: 'dt-center'
            }
        ]
    });
});