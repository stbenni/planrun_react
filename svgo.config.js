export default {
  multipass: true,
  floatPrecision: 4,
  plugins: [
    {
      name: 'preset-default',
      params: {
        overrides: {
          convertPathData: {
            forceAbsolutePath: true,
            floatPrecision: 4,
          },
        },
      },
    },
  ],
};
