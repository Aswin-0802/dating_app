@extends('errors.layout')

@section('title', 'Too many requests')
@section('code', '429')
@section('heading', 'Please slow down')
@section('message', 'You have made too many requests in a short time. Wait a minute and try again.')
