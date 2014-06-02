<?php
require_once __DIR__ . '/vendor/autoload.php';

use Goutte\Client;

$app = new Silex\Application();


/**
 * get the price of a product from tesco
 */
$app->get('/search/{product}', function ($product) use ($app) {

    $client = new Client();

    $client->setHeader('User-Agent', 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-GB; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6');

    $products = array();

    $product = str_replace(' ', '+', $product);

    $crawler = $client->request('GET', 'http://www.tesco.ie/groceries/Product/Search/Default.aspx?notepad=' . $product);

    $status_code = $client->getResponse()->getStatus();

    if ($status_code == 200) {

        $product_titles = $crawler->filter('h3.inBasketInfoContainer');

        $product_links = $crawler->filterXPath('//div/h3/a')->extract(array('href'));

        $product_images = $crawler->filterXPath('//div/h3/a/span/img')->extract(array('src'));

        $product_prices = $crawler->filter('p.price');

        foreach ($product_titles as $node) {
            $products[] = array('title' => $node->nodeValue);
        }

        $counter = 0;
        foreach ($product_links as $link) {
            $products[$counter]['url'] = 'http://tesco.ie' . $link;
            $counter++;
        }

        $counter = 0;
        foreach ($product_prices as $node) {
            $products[$counter]['price'] = str_replace("€", "&euro;", $node->nodeValue);
            $counter++;
        }

        $counter = 0;
        foreach ($product_images as $image) {
            $products[$counter]['image'] = $image;
            $counter++;
        }

    } else {
        echo 'Unexpected response';
    }

    print_r($products);


    return '';
});

$app->run();