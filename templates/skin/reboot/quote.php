{php}
$quote = array(

1  => "Луна флудить зовет!",
2  => "Тепло и лампы с флудом.",
3  => 'Как сказал товарищ Луна.',
4  => 'Дойдем до 5000!',
4  => 'Бункер — место, где пасутся драмы.'

);

srand ((double) microtime() * 1000000);
$randnum = rand(1,4);

echo"$quote[$randnum]";
{/php}
