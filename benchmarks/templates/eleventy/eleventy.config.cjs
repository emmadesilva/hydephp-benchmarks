module.exports = function (eleventyConfig) {
    eleventyConfig.addFilter('shortDate', (date) =>
        new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
    );

    return {
        dir: { input: 'src', output: '_site' },
    };
};
