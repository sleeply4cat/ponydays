{php}
$smiles = scandir('/var/www/static/smiles/');
unset($smiles[array_search('.', $smiles)]);
unset($smiles[array_search('..', $smiles)]);
echo($smiles[array_rand($smiles, 1)]);
{/php}