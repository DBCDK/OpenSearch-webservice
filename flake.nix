{
  description = "opensearch php dev shell";

  inputs = {
    nixpkgs.url = "github:nixos/nixpkgs/nixos-22.11";
    flake-parts.url = "github:hercules-ci/flake-parts";
  };
  outputs = inputs@{ flake-parts, ... }:
    flake-parts.lib.mkFlake { inherit inputs; } {
      flake = {
        # Put your original flake attributes here.
      };
      systems = [
        # systems for which you want to build the `perSystem` attributes
        "x86_64-linux"
        "aarch64-darwin"
        # ...
      ];
      perSystem = { config, pkgs, ... }: 
      let
        osPHP = pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ all.imagick all.memcached ]);
 
      in {
        devShells.default = pkgs.mkShell {
                buildInputs = [ osPHP osPHP.packages.composer pkgs.subversion ] ; 
        };
      };
    };
}
