@props(['score'])

@php
    if ($score === null || $score === '') {
        $formattedScore = '–';
    } else {
        $numericScore = (float) $score;
        $formattedScore = $numericScore === (float) (int) $numericScore
            ? (string) (int) $numericScore
            : \App\Support\LocalizedNumber::format($numericScore, 2, true);
    }
@endphp

{{ $formattedScore }}
