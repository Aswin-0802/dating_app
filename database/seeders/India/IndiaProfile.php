<?php

declare(strict_types=1);

namespace Database\Seeders\India;

use App\Models\City;
use Illuminate\Database\Eloquent\Builder;

/**
 * What makes a demo member look like they live in Tamil Nadu.
 *
 * The demo seeders are generic; this is the one place that knows names,
 * numbers and places for an India launch. AppUserSeeder asks for a profile
 * when `platform.seed.profile` is set, and IndiaDemoSeeder sets it.
 */
final class IndiaProfile
{
    /** Cities that get most of the members, with a weight each. Chennai first. */
    public const FOCUS = [
        'Chennai' => 55,
        'Coimbatore' => 16,
        'Madurai' => 10,
        'Tiruchirappalli' => 7,
        'Salem' => 5,
        'Tirunelveli' => 4,
        'Vellore' => 3,
    ];

    /** Share of "man" where a city is deliberately imbalanced, so the balance screen has a row to notice. */
    public const CITY_SKEW = ['Coimbatore' => 0.62];

    public const FIRST_NAMES_WOMEN = [
        'Aishwarya', 'Akshaya', 'Anitha', 'Anjali', 'Archana', 'Bhavani', 'Deepika', 'Divya', 'Gayathri', 'Harini',
        'Janani', 'Kavya', 'Keerthana', 'Lakshmi', 'Madhumitha', 'Meena', 'Nandhini', 'Nithya', 'Pavithra', 'Preethi',
        'Priya', 'Radhika', 'Ramya', 'Revathi', 'Sandhya', 'Saranya', 'Shalini', 'Shruthi', 'Sneha', 'Sowmya',
        'Subha', 'Swathi', 'Thenmozhi', 'Vaishnavi', 'Varsha', 'Vidya', 'Yamuna', 'Ezhil', 'Kalpana', 'Mahalakshmi',
    ];

    public const FIRST_NAMES_MEN = [
        'Aravind', 'Arjun', 'Ashwin', 'Balaji', 'Bharath', 'Dinesh', 'Ganesh', 'Gokul', 'Hari', 'Karthik',
        'Kavin', 'Kumar', 'Manoj', 'Mohan', 'Mukesh', 'Naveen', 'Prakash', 'Pradeep', 'Rajesh', 'Ramesh',
        'Ravi', 'Sakthi', 'Santhosh', 'Saravanan', 'Senthil', 'Siva', 'Sridhar', 'Suresh', 'Surya', 'Tamilarasan',
        'Vignesh', 'Vijay', 'Vimal', 'Vinoth', 'Yuvaraj', 'Ilango', 'Kannan', 'Murugan', 'Prasanna', 'Rahul',
    ];

    public const FIRST_NAMES_ANY = ['Kiran', 'Nila', 'Amudha', 'Inba', 'Thamizh', 'Oviya', 'Sathya', 'Selvam'];

    /** Tamil family names, plus the initial-style surnames common in Tamil Nadu. */
    public const LAST_NAMES = [
        'Subramanian', 'Krishnan', 'Raman', 'Natarajan', 'Sundaram', 'Venkatesan', 'Murugan', 'Pillai', 'Iyer', 'Iyengar',
        'Chettiar', 'Gounder', 'Nadar', 'Rajan', 'Shanmugam', 'Selvaraj', 'Palaniswamy', 'Thangaraj', 'Kannan', 'Ganesan',
        'Ramasamy', 'Balasubramanian', 'Devarajan', 'Sekar', 'Ravichandran', 'Anandan', 'Elango', 'Kumar', 'Varadarajan', 'Srinivasan',
    ];

    public const EMAIL_DOMAINS = ['gmail.com', 'yahoo.in', 'outlook.com', 'rediffmail.com'];

    /** Members are drawn from Tamil Nadu's selectable cities only. */
    public static function cities(): Builder
    {
        return City::query()->selectable()->whereHas('state', fn (Builder $q) => $q->where('code', 'TN'));
    }

    /** An Indian mobile number: +91 and ten digits starting 6–9. */
    public static function phone($faker): string
    {
        return '+91'.$faker->randomElement(['6', '7', '8', '9']).$faker->numerify('#########');
    }

    /** iOS · Android · web mix for India: Android-heavy. */
    public const SIGNUP_SOURCES = ['ios' => 22, 'android' => 70, 'web' => 8];
}
