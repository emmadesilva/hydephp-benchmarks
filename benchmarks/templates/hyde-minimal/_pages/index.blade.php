<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bench</title>
</head>
<body>
<header><a href="index.html">Bench</a></header>
<main>
    <ul>
        @foreach(\Hyde\Pages\MarkdownPost::getLatestPosts() as $post)
            <li><a href="{{ $post->getLink() }}">{{ $post->title }}</a> <time>{{ $post->date->short }}</time></li>
        @endforeach
    </ul>
</main>
</body>
</html>
