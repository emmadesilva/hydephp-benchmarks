<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bench</title>
</head>
<body>
<header><a href="/index.html">Bench</a></header>
<main>
    <ul>
        @foreach($posts as $post)
            <li><a href="{{ $post->getPath() }}.html">{{ $post->title }}</a> <time>{{ date('M j, Y', strtotime($post->date)) }}</time></li>
        @endforeach
    </ul>
</main>
</body>
</html>
