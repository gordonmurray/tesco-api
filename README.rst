Tesco-API, an unofficial API
=============================

A simple API to allow you to search for a product and return a list of Products sold by Tesco with a full title, price, link and image.

Developed using a PHP framework called Silex & Goutte.

Installation
------------

Clone a local copy of the repository and navigate to /index.php/search/{product}

For example: http://localhost/tesco-api/index.php/search/coke%20zero

Will produce:

Array
(
    [0] => Array
        (
            [title] => Coke Zero 2 Litre
            [url] => http://tesco.ie/groceries/Product/Details/?id=260507026
            [price] => &euro;2.29 (&euro;0.12/100ml)
            [image] => http://img.tesco.ie/Groceries/pi/355\5000112549355\IDShot_90x90.jpg
        )

    [1] => Array
        (
            [title] => Coke Zero 18X330ml
            [url] => http://tesco.ie/groceries/Product/Details/?id=271424648
            [price] => &euro;7.00 (&euro;0.12/100ml)
            [image] => http://img.tesco.ie/Groceries/pi/704\5449000180704\IDShot_90x90.jpg
        )
)