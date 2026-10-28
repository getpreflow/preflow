<style>
    @page { margin-top: 20mm; }
    h1 { color: #b0306a; font-size: 28pt; }
    .game { font-style: italic; }
</style>
<h1>Certificate</h1>
<p>Awarded to {{ $name }}</p>
@if($game)
<p class="game">for mastering {{ $game }}</p>
@endif
