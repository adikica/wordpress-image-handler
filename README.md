WordPress Image Optimization Function
A lightweight, optimized function for WordPress that ensures images have proper dimensions and automatically selects the most appropriately-sized versions. It solves PageSpeed "serve images that are appropriately-sized" warnings by setting correct width and height attributes and selecting optimal source images based on display dimensions.
Features:

Guarantees width and height attributes to prevent layout shifts
Intelligently selects the most size-appropriate image source
Special handling for small images (icons) under 100px
Full SVG support with proper dimensions
Sets proper aspect-ratio CSS for improved layout stability
Responsive image support with srcset and sizes attributes
Backward compatible with standard WordPress image functions

This solution fixes PageSpeed warnings while maintaining image quality, especially for large images being displayed at smaller sizes.
