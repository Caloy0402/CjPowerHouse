(function($) {
    "use strict";

    // spinner
    var spinner = function() {
        setTimeout(function() {
            if ($('#spinner').length > 0) {
                $('#spinner').removeClass('show');
            }
        }, 1);
    };
    spinner();

    // sidebar Toggler
    $('.sidebar-toggler').on('click', function() {
         $('.sidebar .content').toggleClass('open');
        return false;
    });
    document.querySelector(".sidebar-toggler").addEventListener("click", function () {
        document.querySelector(".sidebar").classList.toggle("open");
        document.querySelector(".content").classList.toggle("open");
    });

//chart color
Chart.defaults.color = "#6C7293";
Chart.defaults.borderColor = "#000000";

    document.getElementById('role').innerText = 'Cashier';
    document.getElementById('role').innerText = 'Admin';


})(jQuery);