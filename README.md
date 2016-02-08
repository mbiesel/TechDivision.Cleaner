# TechDivision.Cleaner

## Introduction

This package provides a command controller for the Neos.io CMS, which search for orphan resources and assets and remove them from database and filesystem.

## Installation

add following to your composer.json

    "repositories": [
        {
            "type": "git",
            "url": "https://github.com/mbiesel/TechDivision.Cleaner.git"
        }
    ],
    "require": {
		"techdivision/cleaner": "~0.1"
	}

## Run

	./flow clean:resources
