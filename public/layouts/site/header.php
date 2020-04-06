<?php
if(ACCESS_ALLOWED != 1){
    exit('Requested document not found on this server.');
}
?>
<!doctype html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>404 - This page can not be found</title>
        <meta name="author" content="Josiah Gerald">
        <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
        <meta name="copyright" content="Josiah Gerald">
        <meta name="description" content="404 error pages to show your visitors when they encounter a page that don't exist anymore">
        <meta name="keywords" content="404 page">
        <link href='http://fonts.googleapis.com/css?family=Pacifico' rel='stylesheet' type='text/css'>
        <style type="text/css">
            html, body {
                margin:0; 
                padding:0; 
                background-color:#496287; 
                font-family: 'Pacifico', cursive; 
                width:100%; 
                height:100%; 
                overflow:hidden;
            }
            body { 
                background-image:url(<?php echo ASSETS_IMG; ?>broken.png); 
                background-size:cover; 
                background-repeat:no-repeat; 
                background-position:bottom;
            }
            html { 
                display:table;
            }
            body { 
                display:table-cell; 
                vertical-align:middle;
            }
            .content-wrapper { 
                width:100%; 
                max-width:450px;
                margin:0 auto;
            }
            .content-wrapper img { 
                width:100%;
            }
            .content-wrapper p { 
                text-align:center; 
                font-size:34px; 
                color:#f5ecde; 
                font-stretch:wider; 
                transform: rotate(357deg) ;
                -webkit-transform: rotate(357deg) ;
                -moz-transform: rotate(357deg) ;
                -o-transform: rotate(357deg) ;
                -ms-transform: rotate(357deg) ; 
                margin-top:-20px; 
                margin-left:45px; 
                line-height:35px;
            }
            .content-wrapper p span { 
                font-size:24px; 
            }
            .content-wrapper p a { 
                text-decoration:none;
            }
            .content-wrapper p span:hover {
                cursor:pointer;
            }
        </style>
    </head>

    <body>